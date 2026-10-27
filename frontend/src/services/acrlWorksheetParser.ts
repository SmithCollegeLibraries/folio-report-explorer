import type { AcrlAnswerType, AcrlQuestionPart, AcrlSheet, AcrlWorksheetParseResult } from '../types/acrl';
import type { PdfTextItem } from './acrlPdfText';
import { pdfItemsToText } from './acrlPdfText';
import { detectTrendsAnswerLayouts } from './acrlWorksheetLayoutParser';
import type { PdfPageProgress } from './acrlPdfPages';

const SECTION_NAMES = [
  'Library characteristics',
  'Staffing',
  'Expenses',
  'Library collections',
  'Institutional repositories',
  'Library services',
  'Local characteristics and enrollment',
  'Comments',
] as const;

const SECTION_HEADING_ALIASES = [
  { heading: 'Staffing Types, FTEs, and Expenses', section: 'Staffing' },
  { heading: 'Staffing FTEs', section: 'Staffing' },
  { heading: 'Branch/Independent Libraries', section: 'Library characteristics' },
  { heading: 'Expenses (Exclude Staff)', section: 'Expenses' },
  { heading: 'Materials Expenses', section: 'Expenses' },
  { heading: 'Other Operations and Maintenance Expenses', section: 'Expenses' },
] as const;

const PDF_PART_BASES = new Set(['1', '2', '3', '4', '5', '6', '40', '41', '42', '43', '44', '70', '71', '72', '73']);

export async function parseWorksheetFile(file: File, onPageProgress?: PdfPageProgress): Promise<AcrlWorksheetParseResult> {
  const { extractPdfItemPages } = await import('./acrlPdfPages');
  return parseWorksheetPositionedPages(await extractPdfItemPages(file, onPageProgress));
}

export function parseWorksheetPositionedPages(pages: PdfTextItem[][]): AcrlWorksheetParseResult {
  const parsed = parseWorksheetPages(pages.map((items) => pdfItemsToText(items)));
  const layouts = detectTrendsAnswerLayouts(pages, parsed.items);
  return {
    ...parsed,
    items: layouts.items,
    layoutWarnings: layouts.layoutWarnings,
  };
}

export async function sha256ForFile(file: File): Promise<string> {
  const bytes = await file.arrayBuffer();
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function parseWorksheetPages(pages: string[]): AcrlWorksheetParseResult {
  const items: AcrlQuestionPart[] = [];
  const unresolved: string[] = [];
  const unresolvedCalculations: string[] = [];
  const pendingCalculations: Array<{ key: string; references: string[] }> = [];
  let section = '';
  let sheet: AcrlSheet = 'survey';
  let current: AcrlQuestionPart | null = null;
  let order = 0;

  const saveCurrent = () => {
    if (current && current.prompt.trim()) {
      items.push({ ...current, prompt: cleanText(current.prompt) });
    } else if (current) {
      unresolved.push(`${current.question_id}${current.part}`);
    }
    current = null;
  };

  pages.forEach((page) => {
    page.split(/\r?\n/).forEach((rawLine) => {
      const line = cleanText(rawLine);
      if (!line) return;

      if (/^(?:20\d{2}\s+)?trends(?:\s*:|$)/i.test(line)) {
        saveCurrent();
        section = 'Trends';
        sheet = 'trends';
        return;
      }
      if (isPageFurniture(line)) return;
      const matchedSection = detectSection(line);
      if (matchedSection) {
        saveCurrent();
        section = matchedSection;
        sheet = 'survey';
        return;
      }
      if (isPromptBoundary(line)) {
        saveCurrent();
        return;
      }

      const question = parseQuestionLine(line);
      if (question) {
        saveCurrent();
        const normalized = normalizePdfIdentifier(question.id, question.prompt);
        const itemSheet: AcrlSheet = sheet === 'trends' || /^T\d+$/i.test(normalized.questionId) ? 'trends' : sheet;
        const questionId = itemSheet === 'trends' && /^\d+$/.test(normalized.questionId)
          ? `T${normalized.questionId}`
          : normalized.questionId;
        current = {
          sheet: itemSheet,
          question_id: questionId,
          part: normalized.part,
          section: sectionForQuestion(itemSheet, normalized.questionId, section),
          prompt: question.prompt,
          answer_type: inferAnswerType(itemSheet, normalized.questionId, normalized.part, question.prompt),
          required: question.required ? 'yes' : 'no',
          calculated_from: null,
          survey_order: ++order,
        };
        return;
      }

      const references = calculatedReferences(line);
      if (references) {
        if (current) {
          current.calculated_from = `sum of ${references.join(', ')}`;
          pendingCalculations.push({ key: itemKey(current), references });
        } else {
          unresolved.push(line);
        }
        return;
      }
      if (isCalculationLine(line)) {
        if (current) unresolvedCalculations.push(`${current.question_id}${current.part}: unparsed sum rule`);
        else unresolved.push(line);
        return;
      }

      if (looksLikeQuestion(line)) {
        unresolved.push(line);
        return;
      }
      if (current) {
        current.prompt = `${current.prompt} ${line}`.trim();
      }
    });
  });

  saveCurrent();
  pendingCalculations.forEach(({ key, references }) => {
    const target = items.find((item) => itemKey(item) === key);
    if (!target) return;
    const resolved: string[] = [];
    references.forEach((reference) => {
      const match = resolveCalculationReference(reference, target, items);
      if (match) resolved.push(match);
      else {
        resolved.push(reference);
        unresolvedCalculations.push(`${target.question_id}${target.part}: ${reference}`);
      }
    });
    target.calculated_from = `sum of ${resolved.join(', ')}`;
  });
  return { items, unresolved, unresolvedCalculations };
}

function parseQuestionLine(line: string): { id: string; prompt: string; required: boolean } | null {
  const match = line.match(/^(\*)?\s*([A-Za-z]?\d+[A-Za-z]?\.?)\s*(\*)?(?:\s+|[.)]\s+)(.*)$/);
  if (!match) return null;
  const id = match[2].replace(/\.$/, '');
  const prompt = cleanText(match[4] || '');
  if (!/^(?:T\d+|\d+[A-Za-z]?)$/i.test(id) || !prompt) return null;
  return { id, prompt, required: Boolean(match[1] || match[3]) };
}

function normalizePdfIdentifier(rawId: string, prompt: string): { questionId: string; part: string } {
  const id = rawId.trim().replace(/\.$/, '');
  const partId = id.match(/^(\d+)([A-Z])$/);
  if (partId && PDF_PART_BASES.has(partId[1])) {
    return { questionId: partId[1], part: partId[2] };
  }
  const base = id;
  const text = prompt.toLowerCase();
  if (['40', '42', '43', '44', '60'].includes(base) && /\bphysical\b/.test(text)) {
    return { questionId: base, part: 'A' };
  }
  if (['41', '40', '42', '43', '44', '60'].includes(base) && /\b(?:digital|electronic)\b/.test(text)) {
    return { questionId: base, part: 'B' };
  }
  return { questionId: id, part: '' };
}

function inferAnswerType(sheet: AcrlSheet, id: string, part: string, prompt: string): AcrlAnswerType {
  const text = prompt.toLowerCase();
  if (sheet === 'trends') {
    return /\b(?:please describe|please share|what resources or support|describe the nature)\b/.test(text) ? 'text' : 'choice';
  }
  if (/^(?:does|do|is|are|has|have|will|can)\b/.test(text)) return 'yes/no';
  if (id === '100' || id === '101' || /\b(?:please describe|please share)\b/.test(text)) return 'text';
  if (part === 'B' && ['1', '2', '3', '4', '5', '6'].includes(id)) return 'currency';
  if (id === '6' && part === 'C') return 'currency';
  if (/\b(?:salary|salaries|wages|expenditures?|expense|cost|fringe benefits)\b/.test(text)) return 'currency';
  if (/\b(?:purchase|commitments to subscriptions|preservation services|materials\/services)\b/.test(text)
    && /^\d+$/.test(id) && Number(id) >= 20 && Number(id) <= 31) return 'currency';
  return 'number';
}

function calculatedReferences(line: string): string[] | null {
  const match = line.match(/^(?:calculated\s+)?sum\s+of\s+(?:questions\s+)?(.+?)\.?$/i);
  if (!match) return null;
  const expression = match[1].trim().replace(/\.$/, '');
  const identifiers = expression.match(/[A-Za-z]?\d+[A-Za-z]?/g) || [];
  if (identifiers.length < 1) return null;
  const separatorsOnly = expression.replace(/[A-Za-z]?\d+[A-Za-z]?/g, '#');
  if (!/^#(?:(?:\s*,\s*(?:and\s+)?|\s+and\s+|\s*\+\s*)#)*$/i.test(separatorsOnly)) return null;
  return identifiers;
}

function isCalculationLine(line: string): boolean {
  return /^(?:calculated\s+)?sum\s+of\b/i.test(line);
}

function itemKey(item: AcrlQuestionPart): string {
  return `${item.sheet}:${item.question_id}:${item.part}`;
}

function resolveCalculationReference(reference: string, target: AcrlQuestionPart, items: AcrlQuestionPart[]): string | null {
  const find = (questionId: string, part: string) => items.find((item) => item.question_id.toLowerCase() === questionId.toLowerCase()
    && item.part.toLowerCase() === part.toLowerCase());
  const exactId = find(reference, '');
  if (exactId) return `${exactId.question_id}${exactId.part}`;
  const partId = reference.match(/^(\d+)([A-Za-z])$/);
  if (partId) {
    const exactPart = find(partId[1], partId[2]);
    return exactPart ? `${exactPart.question_id}${exactPart.part}` : null;
  }
  if (/^\d+$/.test(reference) && target.part) {
    const samePart = find(reference, target.part);
    if (samePart) return `${samePart.question_id}${samePart.part}`;
    return null;
  }
  if (/^\d+$/.test(reference)) {
    const matches = items.filter((item) => item.sheet === target.sheet
      && item.question_id.toLowerCase() === reference.toLowerCase()
      && item.part !== '');
    const uniqueParts = new Map(matches.map((item) => [item.part.toLowerCase(), item]));
    if (uniqueParts.size === 1) {
      const onlyPart = uniqueParts.values().next().value as AcrlQuestionPart | undefined;
      if (onlyPart) return `${onlyPart.question_id}${onlyPart.part}`;
    }
  }
  return null;
}

function looksLikeQuestion(line: string): boolean {
  return /^\s*\*/.test(line) || /^\s*[A-Za-z]?\d+[A-Za-z]?(?:[.)]\s+|\s+)/.test(line);
}

function isPageFurniture(line: string): boolean {
  return /^(?:20\d{2}(?:\s|$)|\d{1,2}\.?$|ACRL\s+Survey|Page\s+\d+\b)/i.test(line);
}

function detectSection(line: string): string | null {
  const normalized = line.replace(/&/g, 'and').replace(/:$/, '').trim().toLowerCase();
  const section = SECTION_NAMES.find((name) => {
    const normalizedName = name.toLowerCase();
    return normalized === normalizedName
      || (!['staffing', 'expenses'].includes(normalizedName) && normalized.startsWith(`${normalizedName} `));
  });
  if (section) return section;
  const alias = SECTION_HEADING_ALIASES.find(({ heading }) => {
    const normalizedHeading = heading.toLowerCase();
    return normalized === normalizedHeading || normalized.startsWith(`${normalizedHeading} `);
  });
  return alias ? alias.section : null;
}

function sectionForQuestion(sheet: AcrlSheet, questionId: string, currentSection: string): string {
  if (sheet === 'trends') return 'Trends';
  const normalizedId = questionId.toLowerCase();
  if (normalizedId === '90' || /^91[a-e]?$/.test(normalizedId)) return 'Local characteristics and enrollment';
  if (normalizedId === '100' || normalizedId === '101') return 'Comments';
  return currentSection || 'Unplaced';
}

function isPromptBoundary(line: string): boolean {
  return /^notes\s*[-–—]/i.test(line)
    || /^(?:staffing expenses|expenses \(exclude staff\)|materials expenses)$/i.test(line);
}

function cleanText(text: string): string {
  return text.replace(/\s+/g, ' ').trim();
}
