import type { AcrlEntryQuestion, AcrlEntryResponse } from '../types/acrl';

export const acrlBenchmarkColumns = [
  'sheet',
  'section',
  'question_id',
  'part',
  'question',
  'value',
  'previous_year',
  'previous_value',
  'change_percent',
  'note',
] as const;

export type AcrlBenchmarkRow = Record<(typeof acrlBenchmarkColumns)[number], string | number>;

const numericText = /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/;
const formulaStart = /^[=+\-@]/;

function safeTextCell(value: string | number | null | undefined): string | number {
  if (value == null) return '';
  if (typeof value === 'number') return Number.isFinite(value) ? value : '';
  return formulaStart.test(value) ? `'${value}` : value;
}

function trimNumericDecimalZeros(value: string): string {
  const decimal = /^([+-]?\d+)\.(\d*)$/.exec(value);
  if (!decimal) return value;
  const fraction = decimal[2].replace(/0+$/, '');
  return fraction ? `${decimal[1]}.${fraction}` : decimal[1];
}

function safeNumericCell(value: string | number | null | undefined): string | number {
  if (value == null) return '';
  if (typeof value === 'number') return Number.isFinite(value) ? value : '';
  const normalized = trimNumericDecimalZeros(value);
  return formulaStart.test(normalized) && !numericText.test(normalized) ? `'${normalized}` : normalized;
}

function answerValue(question: AcrlEntryQuestion): string | number | null {
  if (question.answerType === 'number' || question.answerType === 'currency') {
    return question.answer.value_number ?? question.answer.value_text;
  }

  const storedValue = question.answer.value_text ?? question.answer.value_number;
  if (question.answerType === 'yes/no') {
    const normalized = typeof storedValue === 'string' ? storedValue.trim().toLowerCase() : '';
    if (normalized === 'yes') return 'Yes';
    if (normalized === 'no') return 'No';
    return question.answerDisplay ?? storedValue;
  }
  if (question.answerType === 'choice') return question.answerDisplay ?? storedValue;
  return question.answer.value_text;
}

export function buildAcrlBenchmarkRows(entry: AcrlEntryResponse): AcrlBenchmarkRow[] {
  const pageQuestions = entry.sections.flatMap((section, sectionOrder) => section.questions.map((question, questionOrder) => ({
    question,
    section: section.name,
    sectionOrder,
    questionOrder,
  })));
  pageQuestions.sort((left, right) => {
    const leftSheetOrder = left.question.sheet === 'survey' ? 0 : 1;
    const rightSheetOrder = right.question.sheet === 'survey' ? 0 : 1;
    return leftSheetOrder - rightSheetOrder
      || left.question.surveyOrder - right.question.surveyOrder
      || left.sectionOrder - right.sectionOrder
      || left.questionOrder - right.questionOrder;
  });

  return pageQuestions.map(({ question, section }) => {
    const changePercent = question.comparison.changePercent;
    return {
      sheet: safeTextCell(question.sheet),
      section: safeTextCell(section),
      question_id: safeTextCell(question.questionId),
      part: safeTextCell(question.part),
      question: safeTextCell(question.prompt),
      value: question.answerType === 'number' || question.answerType === 'currency'
        ? safeNumericCell(answerValue(question))
        : safeTextCell(answerValue(question)),
      previous_year: entry.previousYear > 0 ? entry.previousYear : '',
      previous_value: question.answerType === 'number' || question.answerType === 'currency'
        ? safeNumericCell(question.comparison.previousValue)
        : safeTextCell(question.comparison.previousValue),
      change_percent: changePercent !== null && Number.isFinite(changePercent) ? Number(changePercent.toFixed(1)) : '',
      note: safeTextCell(question.answer.note),
    };
  });
}
