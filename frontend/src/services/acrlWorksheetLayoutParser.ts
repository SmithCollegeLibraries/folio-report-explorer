import type { AcrlAnswerOptions, AcrlQuestionPart } from '../types/acrl';
import { pdfItemsToPositionedText, type PdfTextItem, type PositionedPdfText } from './acrlPdfText';

interface WorksheetLine {
  page: number;
  y: number;
  items: PositionedPdfText[];
  text: string;
}

interface QuestionStart {
  id: string;
  page: number;
  line: number;
}

interface LayoutResult {
  items: AcrlQuestionPart[];
  layoutWarnings: string[];
}

const TEXT_OPTIONS: AcrlAnswerOptions = { kind: 'text', rows: [], choices: [], allowOther: false };
const CHECKBOX_MARKS = new Set(['☐', '□']);
const CIRCLE_MARKS = new Set(['〇', '○', '◯']);

export function detectTrendsAnswerLayouts(pages: PdfTextItem[][], items: AcrlQuestionPart[]): LayoutResult {
  const lines = pages.map((page, pageIndex) => pageLines(page, pageIndex));
  const starts = findQuestionStarts(lines, items);
  const trends = new Map(items.filter((item) => item.sheet === 'trends').map((item) => [item.question_id, item]));
  const layoutWarnings: string[] = [];

  starts.forEach((start, index) => {
    const question = trends.get(start.id);
    if (!question) return;
    const segment = questionSegment(lines, start, starts[index + 1]);
    const layout = detectLayout(question, segment);
    if (layout) {
      question.answer_options = layout;
      question.answer_type = layout.kind === 'text' ? 'text' : 'choice';
      return;
    }
    if (question.answer_type === 'text') {
      question.answer_options = { ...TEXT_OPTIONS };
      return;
    }
    question.answer_type = 'text';
    question.answer_options = { ...TEXT_OPTIONS };
    layoutWarnings.push(`${question.question_id}: answer layout not recognized; using free text.`);
  });

  trends.forEach((question) => {
    if (question.answer_options) return;
    if (question.answer_type === 'text') {
      question.answer_options = { ...TEXT_OPTIONS };
    } else {
      question.answer_type = 'text';
      question.answer_options = { ...TEXT_OPTIONS };
      layoutWarnings.push(`${question.question_id}: answer layout not recognized; using free text.`);
    }
  });
  return { items, layoutWarnings };
}

function pageLines(items: PdfTextItem[], page: number): WorksheetLine[] {
  const rows: WorksheetLine[] = [];
  pdfItemsToPositionedText(items).forEach((item) => {
    let row = rows[rows.length - 1];
    if (!row || Math.abs(row.y - item.y) > 3) {
      row = { page, y: item.y, items: [], text: '' };
      rows.push(row);
    }
    row.items.push(item);
    row.text = row.items.map((part) => part.text).join(' ').replace(/\s+/g, ' ').trim();
  });
  return rows;
}

function findQuestionStarts(lines: WorksheetLine[][], items: AcrlQuestionPart[]): QuestionStart[] {
  const trendsIds = new Set(items.filter((item) => item.sheet === 'trends').map((item) => item.question_id));
  const starts: QuestionStart[] = [];
  lines.forEach((pageLines, page) => pageLines.forEach((line, lineIndex) => {
    const match = line.text.match(/^([1-9])\.\s+/);
    const id = match ? `T${match[1]}` : '';
    if (id && trendsIds.has(id)) starts.push({ id, page, line: lineIndex });
  }));
  return starts;
}

function questionSegment(lines: WorksheetLine[][], start: QuestionStart, next?: QuestionStart): PositionedPdfText[] {
  const segment: PositionedPdfText[] = [];
  for (let page = start.page; page < lines.length && (!next || page <= next.page); page += 1) {
    const first = page === start.page ? start.line + 1 : 0;
    const last = next && page === next.page ? next.line : lines[page].length;
    lines[page].slice(first, last).forEach((line) => line.items.forEach((item) => segment.push({ ...item, page })));
  }
  return segment;
}

function detectLayout(question: AcrlQuestionPart, items: PositionedPdfText[]): AcrlAnswerOptions | null {
  const checkboxes = items.filter((item) => CHECKBOX_MARKS.has(item.text));
  if (checkboxes.length >= 2) {
    const choices = labelsForControls(checkboxes, items);
    return choices.length === checkboxes.length && choices.every(Boolean)
      ? { kind: 'checkbox', rows: [], choices, allowOther: choices.some(isOtherLabel) }
      : null;
  }

  const circles = items.filter((item) => CIRCLE_MARKS.has(item.text));
  const columns = gridColumns(items);
  if (circles.length >= 2 && columns.length >= 2) {
    const grid = gridOptions(circles, columns, items);
    if (grid) return grid;
  }

  const radios = items.filter((item) => item.text.toLowerCase() === 'o');
  if (radios.length >= 2) {
    const choices = labelsForControls(radios, items);
    if (choices.length === radios.length && choices.every(Boolean)) {
      const prompt = question.prompt.toLowerCase();
      const rating = /^t1$/i.test(question.question_id)
        || (prompt.includes('compared to') && choices.some((choice) => /\bhigher\b/i.test(choice)) && choices.some((choice) => /\blower\b/i.test(choice)));
      return { kind: rating ? 'rating' : 'radio', rows: [], choices, allowOther: choices.some(isOtherLabel) };
    }
  }
  return null;
}

function labelsForControls(controls: PositionedPdfText[], items: PositionedPdfText[]): string[] {
  const ordered = [...controls].sort((left, right) => (left.page || 0) - (right.page || 0) || right.y - left.y || left.x - right.x);
  const labels = ordered.map((control) => {
    const nearby = items.filter((item) => item.x > control.x + 5 && item.x < control.x + 380
      && Math.abs(item.y - control.y) <= 14 && !isControl(item.text));
    const closestDistance = nearby.length ? Math.min(...nearby.map((item) => Math.abs(item.y - control.y))) : Infinity;
    const parts = nearby.filter((item) => Math.abs(item.y - control.y) <= Math.min(14, closestDistance + 9))
      .sort((left, right) => right.y - left.y || left.x - right.x);
    return normalizeLabel(parts.map((item) => item.text).join(' '));
  });
  return labels;
}

function gridColumns(items: PositionedPdfText[]): PositionedPdfText[] {
  return items.filter((item) => /^(?:yes|no|don['’]?t know)$/i.test(item.text.trim()))
    .sort((left, right) => left.x - right.x)
    .filter((item, index, all) => index === 0 || Math.abs(item.x - all[index - 1].x) > 10);
}

function gridOptions(circles: PositionedPdfText[], columns: PositionedPdfText[], items: PositionedPdfText[]): AcrlAnswerOptions | null {
  const markerRows: Array<{ page: number; y: number; markers: PositionedPdfText[] }> = [];
  [...circles].sort((left, right) => (left.page || 0) - (right.page || 0) || right.y - left.y || left.x - right.x).forEach((circle) => {
    let row = markerRows[markerRows.length - 1];
    if (!row || row.page !== (circle.page || 0) || Math.abs(row.y - circle.y) > 3) {
      row = { page: circle.page || 0, y: circle.y, markers: [] };
      markerRows.push(row);
    }
    row.markers.push(circle);
  });
  if (markerRows.some((row) => row.markers.length !== columns.length)) return null;

  const labelItems = items.filter((item) => item.x >= 70 && item.x < columns[0].x - 20
    && !isControl(item.text) && !isGridHeading(item.text));
  const consumed = new Set<PositionedPdfText>();
  const rowLabels = markerRows.map((row) => {
    const nearby = labelItems.filter((item) => (item.page || 0) === row.page && Math.abs(item.y - row.y) <= 14);
    if (!nearby.length) return null;
    const closestDistance = Math.min(...nearby.map((item) => Math.abs(item.y - row.y)));
    const parts = nearby.filter((item) => Math.abs(item.y - row.y) <= Math.min(14, closestDistance + 9))
      .sort((left, right) => right.y - left.y || left.x - right.x);
    parts.forEach((item) => consumed.add(item));
    return { page: row.page, y: row.y, label: normalizeLabel(parts.map((item) => item.text).join(' ')) };
  });
  if (rowLabels.some((row) => !row || !row.label)) return null;

  const unmarkedOthers = labelItems.filter((item) => !consumed.has(item) && isOtherLabel(item.text));
  const rows = [
    ...rowLabels.filter((row): row is { page: number; y: number; label: string } => Boolean(row)),
    ...unmarkedOthers.map((item) => ({ page: item.page || 0, y: item.y, label: normalizeLabel(item.text) })),
  ].sort((left, right) => left.page - right.page || right.y - left.y).map((row) => row.label);
  if (!rows.length) return null;
  return {
    kind: 'grid',
    rows,
    choices: columns.map((column) => normalizeLabel(column.text)),
    allowOther: rows.some(isOtherLabel),
  };
}

function normalizeLabel(text: string): string {
  return text.replace(/\s+/g, ' ').trim().replace(/\s+([:;,.])/g, '$1');
}

function isOtherLabel(text: string): boolean {
  return /^other\b/i.test(text.trim());
}

function isGridHeading(text: string): boolean {
  return /^(?:yes|no|don['’]?t know)$/i.test(text.trim());
}

function isControl(text: string): boolean {
  return CHECKBOX_MARKS.has(text) || CIRCLE_MARKS.has(text) || text.toLowerCase() === 'o';
}
