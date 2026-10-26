import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import type { AcrlAnswerType, AcrlQuestionPart, AcrlSheet, AcrlWorksheetParseResult } from '../types/acrl';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

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

interface PdfTextItem {
  str?: string;
  transform?: number[];
}

export async function parseWorksheetFile(file: File): Promise<AcrlWorksheetParseResult> {
  GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
  const bytes = new Uint8Array(await file.arrayBuffer());
  const loadingTask = getDocument({ data: bytes });
  const document = await loadingTask.promise;
  const pages: string[] = [];
  try {
    for (let pageNumber = 1; pageNumber <= document.numPages; pageNumber += 1) {
      const page = await document.getPage(pageNumber);
      const text = await page.getTextContent();
      pages.push(pdfItemsToText(text.items as PdfTextItem[]));
      page.cleanup();
    }
  } finally {
    await loadingTask.destroy();
  }
  return parseWorksheetPages(pages);
}

export async function sha256ForFile(file: File): Promise<string> {
  const bytes = await file.arrayBuffer();
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function parseWorksheetPages(pages: string[]): AcrlWorksheetParseResult {
  const items: AcrlQuestionPart[] = [];
  const unresolved: string[] = [];
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

      if (isCalculatedFrom(line)) {
        if (current) current.calculated_from = line;
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
  return { items, unresolved };
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

function isCalculatedFrom(line: string): boolean {
  return /^calculated\s+(?:sum\s+of|from)\b/i.test(line);
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

function pdfItemsToText(items: PdfTextItem[]): string {
  const positioned = items
    .filter((item) => typeof item.str === 'string' && item.str.trim())
    .map((item) => ({ text: item.str as string, x: item.transform?.[4] || 0, y: item.transform?.[5] || 0 }))
    .sort((left, right) => right.y - left.y || left.x - right.x);
  const lines: Array<{ y: number; parts: string[] }> = [];
  positioned.forEach((item) => {
    let line = lines[lines.length - 1];
    if (!line || Math.abs(line.y - item.y) > 3) {
      line = { y: item.y, parts: [] };
      lines.push(line);
    }
    line.parts.push(item.text);
  });
  return lines.map((line) => cleanText(line.parts.join(' '))).join('\n');
}
