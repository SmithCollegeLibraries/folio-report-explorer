export type AcrlSheet = 'survey' | 'trends';
export type AcrlAnswerType = 'number' | 'currency' | 'yes/no' | 'choice' | 'text';
export type AcrlRequired = 'yes' | 'no' | 'unknown';
export type AcrlReviewStatus = 'unchanged' | 'reworded' | 'renumbered' | 'new' | 'removed';
export type AcrlMatchKind = 'same' | 'reworded' | 'renumbered' | 'split' | 'merged';
export type AcrlLinkDecision = 'approved' | 'rejected';

export interface AcrlLinkDecisionInput {
  sheet: AcrlSheet;
  previous_question_id: string;
  previous_part: string;
  current_question_id: string;
  current_part: string;
  decision: AcrlLinkDecision;
  manual?: boolean;
  match_kind?: AcrlMatchKind;
}

export interface AcrlQuestionPart {
  id?: number;
  sheet: AcrlSheet;
  question_id: string;
  part: string;
  section: string;
  prompt: string;
  answer_type: AcrlAnswerType;
  required: AcrlRequired;
  calculated_from: string | null;
  survey_order: number;
}

export interface AcrlWorksheetParseResult {
  items: AcrlQuestionPart[];
  unresolved: string[];
  unresolvedCalculations?: string[];
}

export interface AcrlSurveyYear {
  surveyYear: number;
  status: 'draft' | 'published';
  sourceFileName: string | null;
  surveyPromptCount: number;
  trendsPromptCount: number;
  surveyItemCount: number;
  trendsItemCount: number;
  totalItemCount: number;
  publishedAt: string | null;
}

export interface AcrlReviewItem {
  status: AcrlReviewStatus;
  matchKind: AcrlMatchKind | null;
  score: number;
  previous: AcrlQuestionPart | null;
  current: AcrlQuestionPart | null;
  topical: boolean;
  decision: AcrlLinkDecision | null;
  linkId: number | null;
}

export interface AcrlReviewResponse {
  surveyYear: number;
  previousYear: number | null;
  status: 'draft' | 'published';
  items: AcrlReviewItem[];
  previousQuestions: AcrlQuestionPart[];
  unresolvedCount: number;
  canPublish: boolean;
  assignmentCopy?: AcrlAssignmentCopyResponse;
}

export interface AcrlUploadResponse {
  success: boolean;
  surveyYear: AcrlSurveyYear;
  review: AcrlReviewResponse;
}

export interface AcrlAnswer {
  value_text: string | null;
  value_number: string | null;
  note: string | null;
  source: 'manual' | 'import' | 'calculated' | null;
  source_label: string | null;
  updated_by: string | null;
  updated_at: string | null;
}

export interface AcrlEntryQuestion {
  sheet: AcrlSheet;
  questionId: string;
  part: string;
  section: string;
  prompt: string;
  answerType: AcrlAnswerType;
  required: AcrlRequired;
  calculatedFrom: string | null;
  surveyOrder: number;
  answer: AcrlAnswer;
  previous: {
    value: number | string | null;
    label: string;
    isSum: boolean;
    linkedValues: Array<{ questionId: string; part: string; value: number | string; note?: string | null }>;
  };
  history: Array<{
    year: number;
    value: number | string | null;
    label: string;
    isSum: boolean;
    linkedValues: Array<{ questionId: string; part: string; value: number | string; note: string | null }>;
    notes: Array<{ questionId: string; part: string; note: string }>;
  }>;
  suggestion: { value: string; label: string; year: number } | null;
  comparison: {
    previousValue: number | string | null;
    previousLabel: string;
    changePercent: number | null;
    highlighted: boolean;
    addNote: boolean;
  };
  calculated: { supported: boolean; raw: string | null; value: number | null; mismatch: boolean; waitingFor: string[] } | null;
}

export interface AcrlEntrySection {
  name: string;
  status: 'open' | 'ready';
  setAt: string | null;
  reopenedAt: string | null;
  filled: number;
  total: number;
  assignees: AcrlAssignmentUser[];
  assignedToCurrentUser: boolean;
  questions: AcrlEntryQuestion[];
}

export interface AcrlAssignmentUser {
  id: number;
  displayName: string;
}

export interface AcrlSectionAssignment {
  section: string;
  assignees: AcrlAssignmentUser[];
}

export interface AcrlSectionAssignmentsResponse {
  surveyYear: number;
  sections: AcrlSectionAssignment[];
}

export interface AcrlAssignmentCopyResponse {
  surveyYear: number;
  sourceYear: number;
  copiedSections: string[];
  skippedSections: string[];
  unmatchedSections: string[];
  alreadyAssigned: boolean;
  failed: boolean;
}

export interface AcrlEntryResponse {
  selectedYear: number;
  previousYear: number;
  years: number[];
  thresholdPercent: number;
  sections: AcrlEntrySection[];
}

export interface AcrlImportPreview {
  surveyYear: number;
  sourceLabel: string;
  overwrite: boolean;
  matchedCount: number;
  unknownCount: number;
  typeErrorCount: number;
  existingChanges: number;
  items: Array<{
    line: number;
    sheet?: string;
    questionId?: string;
    part?: string;
    status: string;
    currentValue: string | null;
    value: string | null;
    error: string | null;
  }>;
}
