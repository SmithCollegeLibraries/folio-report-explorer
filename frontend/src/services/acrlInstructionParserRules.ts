import type {
  AcrlInstructionBlock,
  AcrlInstructionKind,
  AcrlInstructionQuestionRef,
} from '../types/acrl';

export interface InstructionDraft extends AcrlInstructionBlock {
  questionKeys: string[];
  lines: string[];
}

const DEFINITION_HEADINGS = [
  'title', 'discovery system', 'government documents', 'government document', 'microforms', 'microform',
  'serials', 'serial', 'interlibrary loan', 'ill', 'collections', 'collection', 'electronic resources',
  'digital resources', 'branch libraries', 'headcount', 'interlibrary loan service',
];

const SECTION_HEADINGS = [
  'library characteristics', 'staffing', 'staffing expenses', 'expenses', 'collections', 'library collections',
  'institutional repositories', 'library services', 'local characteristics and enrollment', 'student enrollment',
];

export function parseGroupHeading(line: string, questionByKey: Map<string, AcrlInstructionQuestionRef>): { blockKey: string; questionKeys: string[]; kind: AcrlInstructionKind } | null {
  const normalized = line.replace(/^question\s+/i, '').replace(/^q\s*/i, '').replace(/[.:]$/, '').trim();
  let tokens: string[] = [];
  let kind: AcrlInstructionKind = 'group';
  let match = normalized.match(/^(\d+)\s*([A-Z])\s*[–—-]\s*(\d+)\s*\2(?:\s|$|[.:])/i);
  if (match) {
    const start = Number(match[1]);
    const end = Number(match[3]);
    if (end >= start && end - start <= 50) tokens = Array.from({ length: end - start + 1 }, (_, index) => `${start + index}${match?.[2]}`);
  }
  match = normalized.match(/^(\d+)\s*([A-Z])\s*[–—-]\s*([A-Z])(?:\s|$|[.:])/i);
  if (!tokens.length && match) {
    const start = match[2].toUpperCase().charCodeAt(0);
    const end = match[3].toUpperCase().charCodeAt(0);
    if (end >= start && end - start <= 10) tokens = Array.from({ length: end - start + 1 }, (_, index) => `${match?.[1]}${String.fromCharCode(start + index)}`);
  }
  match = normalized.match(/^(\d+)\s*([A-Z])\s*[–—-]\s*(\d+)\s*([A-Z])(?:\s|$|[.:])/i);
  if (!tokens.length && match && match[1] === match[3]) {
    const start = match[2].toUpperCase().charCodeAt(0);
    const end = match[4].toUpperCase().charCodeAt(0);
    if (end >= start && end - start <= 10) tokens = Array.from({ length: end - start + 1 }, (_, index) => `${match?.[1]}${String.fromCharCode(start + index)}`);
  }
  match = normalized.match(/^(\d+)\s*[/]\s*(\d+)\s*([A-Z])\s*[–—-]\s*(?:\2\s*)?([A-Z])(?:\s|$|[.:])/i);
  if (!tokens.length && match) {
    const start = match[3].toUpperCase().charCodeAt(0);
    const end = match[4].toUpperCase().charCodeAt(0);
    tokens = [match[1], ...Array.from({ length: Math.max(0, end - start + 1) }, (_, index) => `${match?.[2]}${String.fromCharCode(start + index)}`)];
  }
  match = normalized.match(/^(\d+)\s*[.:]?\s*(?:&|and)\s*(\d+)(?:\s|$|[.:])/i);
  if (!tokens.length && match) {
    tokens = [match[1], match[2]];
    kind = 'question';
  }
  if (!tokens.length) return null;

  const keys = tokens.flatMap((token) => questionsForToken(token, questionByKey));
  const suffixRange = normalized.match(/^(\d+)\s*([A-Z])\s*[–—-]\s*([A-Z])(?:\s|$|[.:])/i);
  if (suffixRange) keys.push(...questionsForToken(suffixRange[1], questionByKey));
  const unique = Array.from(new Set(keys));
  if (!unique.length) return null;
  const blockKey = kind === 'question' ? `question:${tokens.map((token) => token.toLowerCase()).join('-')}` : groupBlockKey(tokens);
  return { blockKey, questionKeys: unique, kind };
}

export function parseQuestionHeading(line: string, questionByKey: Map<string, AcrlInstructionQuestionRef>): { token: string; questionKeys: string[] } | null {
  const match = line.match(/^(?:[qQ]\s*)?(\d{1,3}[A-Za-z]?)\.\s+(.+)$/);
  if (!match || /^[a-z]/.test(match[2].trim())) return null;
  const questionKeys = questionsForToken(match[1], questionByKey);
  return questionKeys.length ? { token: match[1], questionKeys } : null;
}

export function questionBlockKey(token: string, questionKeys: string[], questionByKey: Map<string, AcrlInstructionQuestionRef>): string {
  const question = questionKeys.length === 1 ? questionByKey.get(questionKeys[0]) : undefined;
  if (question?.part) return `question:${question.question_id.toLowerCase()}-${question.part.toLowerCase()}`;
  return `question:${token.toLowerCase()}`;
}

export function questionsInScope(questions: AcrlInstructionQuestionRef[], heading: string): string[] {
  const normalized = heading.toLowerCase();
  if (/interlibrary|\bill\b|loan/.test(normalized)) {
    return questions.filter((question) => /^(?:81|82)$/.test(question.question_id)).map(questionKey);
  }
  const scope = normalized.includes('collection') ? 'collection'
    : normalized.includes('staff') ? 'staff'
      : normalized.includes('expense') ? 'expense'
        : normalized.includes('service') ? 'service'
          : normalized.includes('headcount') || normalized.includes('enrollment') ? 'enrollment'
            : normalized.includes('characteristic') ? 'characteristic' : '';
  if (!scope) return [];
  return questions.filter((question) => question.section.toLowerCase().includes(scope)
    && (scope !== 'collection' || /^(?:40|41|42|43|44)$/.test(question.question_id))).map(questionKey);
}

export function definitionScope(heading: string, scopeHeading: string): string {
  if (heading.toLowerCase() === 'title') return 'library collections';
  if (/interlibrary|\bill\b|loan/i.test(heading)) return 'interlibrary loan';
  return scopeHeading || heading;
}

export function isSectionHeading(line: string): boolean {
  return SECTION_HEADINGS.some((heading) => line.toLowerCase() === heading);
}

export function isDefinitionHeading(line: string): boolean {
  return DEFINITION_HEADINGS.some((heading) => line.toLowerCase() === heading);
}

export function isPageFurniture(line: string): boolean {
  return /^\d+$/.test(line)
    || /^2026\s+(?:academic library|acrl|survey|statistics)/i.test(line)
    || /^american library association\b/i.test(line)
    || /^instructions?\s+for\s+the\s+annual/i.test(line);
}

export function isContentsPage(page: string): boolean {
  const lines = page.split(/\r?\n/).map(cleanLine).filter(Boolean);
  if (lines.slice(0, 8).some((line) => /^(?:table of )?contents$/i.test(line))) return true;
  return lines.filter(isContentsLine).length >= 2;
}

export function isSurveyQuestionIndexPage(page: string): boolean {
  return /ACRL Trends\s*&\s*Statistics Survey IPEDS Academic Library Component/i.test(page)
    && /Survey section Question/i.test(page);
}

export function hasInstructionSectionHeading(page: string): boolean {
  return page.split(/\r?\n/).some((line) => isSectionHeading(cleanLine(line)));
}

export function isContentsLine(line: string): boolean {
  return /\.{2,}\s*\d{1,3}\s*$/.test(line);
}

export function isFaqPage(page: string): boolean {
  return page.split(/\r?\n/).slice(0, 5).some((line) => /^ACRL FAQ\b|^Frequently Asked Questions\b/i.test(cleanLine(line)));
}

export function isFaqQuestionLine(line: string): boolean {
  return /^Question\s+\d/i.test(line)
    || /^(?:What|When|Where|Why|How|Can|Could|Does|Do|Should|Would|Is|Are)\b.*\?\s*$/i.test(line);
}

export function questionKeysReferencedByLine(line: string, questionByKey: Map<string, AcrlInstructionQuestionRef>): string[] {
  const keys = new Set<string>();
  const rangePattern = /\bquestions?\s+(\d{1,3}[A-Za-z]?)\s*[–—-]\s*(\d{1,3}[A-Za-z]?|[A-Za-z])\b/ig;
  let match = rangePattern.exec(line);
  while (match) {
    expandQuestionRange(match[1], match[2], questionByKey).forEach((key) => keys.add(key));
    match = rangePattern.exec(line);
  }
  const singlePattern = /\b(?:questions?|lines?)\s+(\d{1,3}[A-Za-z]?)\b/ig;
  match = singlePattern.exec(line);
  while (match) {
    questionsForToken(match[1], questionByKey).forEach((key) => keys.add(key));
    match = singlePattern.exec(line);
  }
  return Array.from(keys);
}

export function rootQuestionId(question: AcrlInstructionQuestionRef): string {
  const child = question.question_id.match(/^(\d+)[a-z]$/i);
  return (child ? child[1] : question.question_id).toLowerCase();
}

export function createDraft(kind: AcrlInstructionKind, blockKey: string, heading: string, questionKeys: string[]): InstructionDraft {
  return { blockKey, heading, text: '', kind, questionKeys, lines: [] };
}

export function questionKey(question: Pick<AcrlInstructionQuestionRef, 'sheet' | 'question_id' | 'part'>): string {
  return `${question.sheet}:${question.question_id}:${question.part}`;
}

export function displayQuestionKey(questionId: string, part: string): string {
  return `${questionId}${part}`;
}

export function instructionOrder(kind: AcrlInstructionKind): number {
  if (kind === 'question') return 0;
  if (kind === 'group') return 1;
  return 2;
}

export function slug(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function questionsForToken(token: string, questionByKey: Map<string, AcrlInstructionQuestionRef>): string[] {
  const suffix = token.slice(-1);
  const base = /^\d+[A-Z]$/i.test(token) ? token.slice(0, -1) : token;
  if (/^\d+[A-Z]$/i.test(token)) {
    const lowerCaseId = /[a-z]$/.test(token)
      ? Array.from(questionByKey.entries()).find(([, question]) => question.question_id.toLowerCase() === token.toLowerCase()
        && question.part === '')
      : undefined;
    if (lowerCaseId) return [lowerCaseId[0]];
    const partKey = Array.from(questionByKey.entries()).find(([, question]) => question.question_id.toLowerCase() === base.toLowerCase()
      && question.part.toUpperCase() === suffix.toUpperCase());
    if (partKey) return [partKey[0]];
    const idKey = Array.from(questionByKey.entries()).find(([, question]) => question.question_id.toLowerCase() === token.toLowerCase()
      && question.part === '');
    return idKey ? [idKey[0]] : [];
  }
  const exact = Array.from(questionByKey.entries()).find(([, question]) => question.question_id.toLowerCase() === token.toLowerCase()
    && question.part === '');
  if (exact) return [exact[0]];
  return Array.from(questionByKey.entries())
    .filter(([, question]) => question.question_id.toLowerCase() === token.toLowerCase() && question.part !== '')
    .map(([key]) => key);
}

function expandQuestionRange(start: string, end: string, questionByKey: Map<string, AcrlInstructionQuestionRef>): string[] {
  const startMatch = start.match(/^(\d+)([A-Za-z]?)$/);
  const endMatch = end.match(/^(?:(\d+))?([A-Za-z]?)$/);
  if (!startMatch || !endMatch) return [];
  const firstNumber = Number(startMatch[1]);
  const lastNumber = Number(endMatch[1] || startMatch[1]);
  if (lastNumber < firstNumber || lastNumber - firstNumber > 50) return [];
  const startSuffix = startMatch[2];
  const endSuffix = endMatch[2] || (endMatch[1] ? '' : startSuffix);
  const tokens: string[] = [];
  if (firstNumber === lastNumber && startSuffix && endSuffix) {
    const firstLetter = startSuffix.toLowerCase().charCodeAt(0);
    const lastLetter = endSuffix.toLowerCase().charCodeAt(0);
    if (lastLetter < firstLetter || lastLetter - firstLetter > 10) return [];
    tokens.push(String(firstNumber));
    for (let letter = firstLetter; letter <= lastLetter; letter += 1) tokens.push(`${firstNumber}${String.fromCharCode(letter)}`);
  } else if (startSuffix && endSuffix) {
    for (let number = firstNumber; number <= lastNumber; number += 1) tokens.push(`${number}${startSuffix}`);
    if (endSuffix.toLowerCase() !== startSuffix.toLowerCase()) tokens.push(`${lastNumber}${endSuffix}`);
  } else {
    for (let number = firstNumber; number <= lastNumber; number += 1) tokens.push(String(number));
  }
  return Array.from(new Set(tokens.flatMap((token) => questionsForToken(token, questionByKey))));
}

function groupBlockKey(tokens: string[]): string {
  let first = tokens[0];
  const last = tokens[tokens.length - 1];
  let firstPartRange = first.match(/^(\d+)([A-Z])$/);
  const lastPartRange = last.match(/^(\d+)([A-Z])$/);
  if (!firstPartRange && /^\d+$/.test(first) && tokens.length > 1) {
    const secondPartRange = tokens[1].match(/^(\d+)([A-Z])$/);
    if (secondPartRange && lastPartRange && secondPartRange[1] === first && secondPartRange[1] === lastPartRange[1]) {
      first = tokens[1];
      firstPartRange = secondPartRange;
    }
  }
  if (firstPartRange && lastPartRange && firstPartRange[1] === lastPartRange[1]) {
    return `group:${firstPartRange[1]}${firstPartRange[2].toLowerCase()}-${lastPartRange[2].toLowerCase()}`;
  }
  return `group:${slug(`${first}-${last}`)}`;
}

export function cleanLine(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}
