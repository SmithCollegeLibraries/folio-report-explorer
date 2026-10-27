import type { AcrlReviewResponse, AcrlSurveyYear, AcrlUploadResponse, AcrlQuestionPart, AcrlLinkDecisionInput, AcrlEntryResponse, AcrlImportPreview } from '../types/acrl';
import type { AcrlStatistic } from '../types/schema';
import { api } from './client';

export async function listAcrl(year?: number): Promise<{ items: AcrlStatistic[]; years: number[] }> {
  const { data } = await api.get('/local/acrl', { params: year ? { year } : {} });
  return data;
}

export async function listAcrlYears(): Promise<number[]> {
  const { data } = await api.get('/local/acrl/years');
  return data.years || [];
}

export async function createAcrlRows(rows: Array<Partial<AcrlStatistic>>): Promise<{ success: boolean; created: number; updated: number }> {
  const { data } = await api.post('/local/acrl', { rows });
  return data;
}

export async function updateAcrlRow(id: number, patch: Partial<AcrlStatistic>): Promise<{ success: boolean; item: AcrlStatistic }> {
  const { data } = await api.put(`/local/acrl/${id}`, patch);
  return data;
}

export async function deleteAcrlRow(id: number): Promise<void> {
  await api.delete(`/local/acrl/${id}`);
}

export async function copyAcrlYear(fromYear: number, toYear: number, overwrite = false): Promise<{ success: boolean; copied: number; updated: number; skipped: number }> {
  const { data } = await api.post('/local/acrl/copy-year', { fromYear, toYear, overwrite });
  return data;
}

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
