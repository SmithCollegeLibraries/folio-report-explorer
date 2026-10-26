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
}

export interface AcrlUploadResponse {
  success: boolean;
  surveyYear: AcrlSurveyYear;
  review: AcrlReviewResponse;
}
