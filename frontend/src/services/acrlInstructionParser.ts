import type {
  AcrlInstructionBlock,
  AcrlInstructionLink,
  AcrlInstructionParseResult,
  AcrlInstructionQuestionRef,
} from '../types/acrl';
import {
  cleanLine,
  createDraft,
  definitionScope,
  displayQuestionKey,
  hasInstructionSectionHeading,
  instructionOrder,
  isContentsLine,
  isContentsPage,
  isDefinitionHeading,
  isFaqPage,
  isFaqQuestionLine,
  isPageFurniture,
  isSectionHeading,
  isSurveyQuestionIndexPage,
  parseGroupHeading,
  parseQuestionHeading,
  questionBlockKey,
  questionKey,
  questionKeysReferencedByLine,
  questionsInScope,
  rootQuestionId,
  slug,
  type InstructionDraft,
} from './acrlInstructionParserRules';
import type { PdfPageProgress } from './acrlPdfPages';

export async function parseInstructionFile(file: File, questions: AcrlInstructionQuestionRef[], onPageProgress?: PdfPageProgress): Promise<AcrlInstructionParseResult> {
  const { extractPdfTextPages } = await import('./acrlPdfPages');
  return parseInstructionPages(await extractPdfTextPages(file, onPageProgress), questions);
}
export function parseInstructionPages(pages: string[], questions: AcrlInstructionQuestionRef[]): AcrlInstructionParseResult {
  const surveyQuestions = questions.filter((question) => question.sheet === 'survey');
  const questionByKey = new Map(surveyQuestions.map((question) => [questionKey(question), question]));
  const drafts: InstructionDraft[] = [];
  const duplicateBlockKeys: string[] = [];
  let current: InstructionDraft | null = null;
  let scopeHeading = '';
  let inFaq = false;
  let inQuestionIndex = false;

  const saveCurrent = () => {
    if (current && (current.kind !== 'definition' || current.lines.some((line) => line.trim()))) {
      current.text = current.lines.join('\n').trim();
      if (drafts.some((draft) => draft.blockKey === current?.blockKey)) {
        if (!duplicateBlockKeys.includes(current.blockKey)) duplicateBlockKeys.push(current.blockKey);
      } else {
        drafts.push(current);
      }
    }
    current = null;
  };

  pages.forEach((page) => {
    if (isSurveyQuestionIndexPage(page)) {
      saveCurrent();
      inQuestionIndex = true;
      return;
    }
    if (inQuestionIndex) {
      if (!hasInstructionSectionHeading(page)) return;
      inQuestionIndex = false;
    }
    if (isContentsPage(page)) return;
    if (isFaqPage(page)) {
      saveCurrent();
      inFaq = true;
    }
    page.split(/\r?\n/).forEach((rawLine) => {
      const line = cleanLine(rawLine);
      if (!line || isPageFurniture(line) || isContentsLine(line)) return;
      if (inFaq && current?.kind === 'definition' && isFaqQuestionLine(line)) saveCurrent();
      if (inFaq && !isSectionHeading(line) && !isDefinitionHeading(line)) {
        if (current?.kind === 'definition') current.lines.push(line);
        return;
      }
      const headingLine = line.replace(/^\*\s*/, '');

      const group = parseGroupHeading(headingLine, questionByKey);
      if (group) {
        saveCurrent();
        current = createDraft(group.kind, group.blockKey, headingLine, group.questionKeys);
        return;
      }

      const question = parseQuestionHeading(headingLine, questionByKey);
      if (question) {
        if (current?.kind === 'group' && current.questionKeys.length > 1
          && question.questionKeys.every((key) => current?.questionKeys.includes(key))) {
          current.lines.push(headingLine);
          return;
        }
        saveCurrent();
        current = createDraft('question', questionBlockKey(question.token, question.questionKeys, questionByKey), headingLine, question.questionKeys);
        return;
      }

      if (isSectionHeading(headingLine)) {
        saveCurrent();
        scopeHeading = headingLine;
        return;
      }

      if (isDefinitionHeading(headingLine)) {
        saveCurrent();
        const scope = definitionScope(headingLine, scopeHeading);
        const keys = questionsInScope(surveyQuestions, scope);
        current = createDraft('definition', `definition:${slug(headingLine)}`, headingLine, keys);
        return;
      }

      if (current) {
        current.lines.push(line);
        if (current.kind === 'group') current.questionKeys.push(...questionKeysReferencedByLine(line, questionByKey));
        if (current.kind === 'question') {
          const parentIds = current.questionKeys.map((key) => questionByKey.get(key)).filter((question): question is AcrlInstructionQuestionRef => Boolean(question))
            .map(rootQuestionId);
          current.questionKeys.push(...questionKeysReferencedByLine(line, questionByKey)
            .filter((key) => {
              const question = questionByKey.get(key);
              return question ? parentIds.includes(rootQuestionId(question)) : false;
            }));
        }
      }
    });
  });
  saveCurrent();

  const blocks = drafts.map(({ questionKeys: _questionKeys, lines: _lines, ...block }) => block);
  const blockByKey = new Map(blocks.map((block) => [block.blockKey, block]));
  const links: AcrlInstructionLink[] = [];
  const linkKeys = new Set<string>();
  drafts.forEach((draft) => {
    draft.questionKeys.forEach((key) => {
      const question = questionByKey.get(key);
      if (!question) return;
      const linkKey = `${key}:${draft.blockKey}`;
      if (linkKeys.has(linkKey)) return;
      linkKeys.add(linkKey);
      links.push({ sheet: question.sheet, question_id: question.question_id, part: question.part, blockKey: draft.blockKey });
    });
  });

  const assignments = surveyQuestions.map((question) => {
    const key = questionKey(question);
    const assigned = links
      .filter((link) => questionKey(link) === key)
      .map((link) => blockByKey.get(link.blockKey))
      .filter((block): block is AcrlInstructionBlock => Boolean(block))
      .sort((left, right) => instructionOrder(left.kind) - instructionOrder(right.kind));
    return { sheet: question.sheet, question_id: question.question_id, part: question.part, blocks: assigned };
  });
  const missingQuestionKeys = assignments
    .filter((assignment) => !assignment.blocks.some((block) => block.kind === 'question' || block.kind === 'group'))
    .map((assignment) => displayQuestionKey(assignment.question_id, assignment.part));
  const individual = assignments.filter((assignment) => assignment.blocks.some((block) => block.kind === 'question')).length;
  const grouped = assignments.filter((assignment) => !assignment.blocks.some((block) => block.kind === 'question')
    && assignment.blocks.some((block) => block.kind === 'group')).length;

  return {
    blocks,
    links,
    assignments,
    missingQuestionKeys,
    duplicateBlockKeys,
    coverage: { individual, grouped, missing: missingQuestionKeys.length },
  };
}
