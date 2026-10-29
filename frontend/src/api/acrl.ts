import type {
  AcrlAssignmentCopyResponse,
  AcrlAssignmentUser,
  AcrlAutofillResponse,
  AcrlAutofillMethodsResponse,
  AcrlEntryResponse,
  AcrlImportPreview,
  AcrlInstructionsSaveResult,
  AcrlInstructionBlock,
  AcrlInstructionLink,
  AcrlLinkDecisionInput,
  AcrlNotesImportPreview,
  AcrlQuestionDetails,
  AcrlQuestionNote,
  AcrlQuestionPart,
  AcrlReviewResponse,
  AcrlSectionAssignmentsResponse,
  AcrlSurveyYear,
  AcrlUploadResponse,
} from '../types/acrl';
import { api } from './client';

export async function listAcrlCatalogueYears(): Promise<AcrlSurveyYear[]> {
  const { data } = await api.get('/acrl/years');
  return data;
}

export async function getAcrlReview(year: number): Promise<AcrlReviewResponse> {
  const { data } = await api.get(`/acrl/years/${year}/review`);
  return data;
}

export async function uploadAcrlWorksheet(input: {
  surveyYear: number;
  fileName: string;
  sha256: string;
  items: AcrlQuestionPart[];
  replacePublished: boolean;
}): Promise<AcrlUploadResponse> {
  const { data } = await api.post('/acrl/worksheets', input);
  return data;
}

export async function saveAcrlLinks(year: number, links: AcrlLinkDecisionInput[]): Promise<AcrlReviewResponse> {
  const { data } = await api.post(`/acrl/years/${year}/links`, { links });
  return data;
}

export async function publishAcrlYear(year: number): Promise<AcrlReviewResponse> {
  const { data } = await api.post(`/acrl/years/${year}/publish`);
  return data;
}

export async function getAcrlEntry(year?: number): Promise<AcrlEntryResponse> {
  const { data } = await api.get(year ? `/acrl/entry/${year}` : '/acrl/entry');
  return data;
}

export async function getAcrlSectionAssignments(year: number): Promise<AcrlSectionAssignmentsResponse> {
  const { data } = await api.get(`/acrl/years/${year}/assignments`);
  return data;
}

export async function searchAcrlApprovedUsers(query: string): Promise<{ users: AcrlAssignmentUser[] }> {
  const { data } = await api.get('/acrl/approved-users', { params: { q: query } });
  return data;
}

export async function saveAcrlSectionAssignments(year: number, section: string, userIds: number[]): Promise<AcrlSectionAssignmentsResponse['sections'][number]> {
  const { data } = await api.post(`/acrl/years/${year}/sections/${encodeURIComponent(section)}/assignments`, { userIds });
  return data;
}

export async function copyAcrlAssignmentsFromPrevious(year: number): Promise<AcrlAssignmentCopyResponse> {
  const { data } = await api.post(`/acrl/years/${year}/assignments/copy-previous`);
  return data;
}

export async function saveAcrlAnswer(year: number, input: {
  sheet: 'survey' | 'trends';
  question_id: string;
  part: string;
  value: string;
  note: string;
  expected_updated_at: string | null;
}): Promise<{ conflict: boolean; answer?: AcrlEntryResponse['sections'][number]['questions'][number]['answer']; current?: unknown }> {
  const { data } = await api.post(`/acrl/years/${year}/answers`, input);
  return data;
}

export async function runAcrlAutofill(year: number, sheet: 'survey' | 'trends', questionId: string, part: string): Promise<AcrlAutofillResponse> {
  const pathPart = encodeURIComponent(part || '_');
  const { data } = await api.post(`/acrl/years/${year}/autofill/${encodeURIComponent(sheet)}/${encodeURIComponent(questionId)}/${pathPart}`);
  return data;
}

export async function getAcrlAutofillMethods(): Promise<AcrlAutofillMethodsResponse> {
  const { data } = await api.get('/acrl/autofill/methods');
  return data;
}

export async function acceptAcrlSuggestions(year: number, section: string, items: Array<{
  sheet: 'survey';
  question_id: string;
  part: string;
}>): Promise<{ conflict: boolean; accepted: number; current?: unknown }> {
  const { data } = await api.post(`/acrl/years/${year}/sections/${encodeURIComponent(section)}/suggestions/accept`, { items });
  return data;
}

export async function setAcrlSectionStatus(year: number, section: string, status: 'open' | 'ready'): Promise<{ section: string; status: 'open' | 'ready' }> {
  const { data } = await api.post(`/acrl/years/${year}/sections/${encodeURIComponent(section)}/status`, { status });
  return data;
}

export async function getAcrlThreshold(): Promise<{ thresholdPercent: number }> {
  const { data } = await api.get('/acrl/threshold');
  return data;
}

export async function saveAcrlThreshold(thresholdPercent: number): Promise<{ thresholdPercent: number }> {
  const { data } = await api.post('/acrl/threshold', { thresholdPercent });
  return data;
}

export async function previewAcrlImport(year: number, input: {
  rows: Array<Record<string, string>>;
  sourceLabel: string;
  overwrite: boolean;
}): Promise<AcrlImportPreview> {
  const { data } = await api.post(`/acrl/years/${year}/import/preview`, input);
  return data;
}

export async function confirmAcrlImport(year: number, input: {
  rows: Array<Record<string, string>>;
  sourceLabel: string;
  overwrite: boolean;
}): Promise<{ preview: AcrlImportPreview; result: { imported: number; unchanged: number; skipped: number } }> {
  const { data } = await api.post(`/acrl/years/${year}/import/confirm`, input);
  return data;
}

export async function saveAcrlInstructions(year: number, input: {
  fileName: string;
  sha256: string;
  blocks: AcrlInstructionBlock[];
  links: AcrlInstructionLink[];
}): Promise<AcrlInstructionsSaveResult> {
  const { data } = await api.post(`/acrl/years/${year}/instructions`, input);
  return data;
}

export async function getAcrlQuestionDetails(year: number, sheet: 'survey' | 'trends', questionId: string, part: string): Promise<AcrlQuestionDetails> {
  const { data } = await api.get(`/acrl/years/${year}/question-details`, { params: { sheet, question_id: questionId, part } });
  return data;
}

export async function addAcrlQuestionNote(year: number, input: {
  sheet: 'survey' | 'trends';
  question_id: string;
  part: string;
  note: string;
  source: string;
  origin_year: number;
}): Promise<AcrlQuestionNote> {
  const { data } = await api.post(`/acrl/years/${year}/question-notes`, input);
  return data;
}

export async function editAcrlQuestionNote(id: number, input: { note: string; source: string; origin_year: number }): Promise<AcrlQuestionNote> {
  const { data } = await api.put(`/acrl/question-notes/${id}`, input);
  return data;
}

export async function deleteAcrlQuestionNote(id: number): Promise<void> {
  await api.delete(`/acrl/question-notes/${id}`);
}

export async function previewAcrlNotesImport(year: number, rows: Array<Record<string, string | number>>): Promise<AcrlNotesImportPreview> {
  const { data } = await api.post(`/acrl/years/${year}/notes/import/preview`, { rows });
  return data;
}

export async function confirmAcrlNotesImport(year: number, rows: Array<Record<string, string | number>>): Promise<{
  preview: AcrlNotesImportPreview;
  result: { imported: number; unchanged: number; skipped: number };
}> {
  const { data } = await api.post(`/acrl/years/${year}/notes/import/confirm`, { rows });
  return data;
}
