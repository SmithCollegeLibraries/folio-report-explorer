import type { AcrlEntryQuestion, AcrlSheet } from '../types/acrl';

export function acrlQuestionAssignmentKey(sheet: AcrlSheet, questionId: string, part: string): string {
  return JSON.stringify([sheet, questionId, part]);
}

export function acrlQuestionLabel(questionId: string, part: string): string {
  return `Q${questionId}${part ? ` · ${part}` : ''}`;
}

export function acrlQuestionAnchorId(question: Pick<AcrlEntryQuestion, 'sheet' | 'questionId' | 'part'>): string {
  const safe = (value: string) => encodeURIComponent(value).replace(/%/g, '-');
  return `acrl-q-${safe(question.sheet)}-${safe(question.questionId)}-${safe(question.part || 'none')}`;
}
