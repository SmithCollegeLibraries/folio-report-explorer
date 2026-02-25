import axios from 'axios';
import type {
  TableSummary,
  SchemaMetadata,
  TableDetail,
  PathResponse,
  BuildResponse,
  ExecuteResponse,
  NlResponse,
  SchemaAskResponse,
  SavedQuery,
  HealthResponse,
  QueryDefinition,
  JobSubmitResponse,
  JobStatusResponse,
  ReportSummary,
  ReportTemplate,
  ReportGenerateResponse,
  ReportRunResponse,
  TrainingHint,
  TrainingHintInput,
  CorrectionInput,
  CorrectionResponse,
} from '../types';

// Derive API URL from VITE_BASE_PATH (e.g. '/folio-report-explorer/api')
// Falls back to VITE_API_URL for dev Docker setup, or plain '/api'
const basePath = (import.meta.env.VITE_BASE_PATH || '').replace(/\/$/, '');
const apiBase = import.meta.env.VITE_API_URL || `${basePath}/api`;

const api = axios.create({
  baseURL: apiBase,
  headers: { 'Content-Type': 'application/json' },
  timeout: 60000,
});

// ─── Schema ───────────────────────────────────────────────────────

export async function fetchSchema(tables?: string[]): Promise<{
  metadata: SchemaMetadata;
  tables: Record<string, TableSummary>;
}> {
  const params = tables ? { tables: tables.join(',') } : {};
  const { data } = await api.get('/schema', { params });
  return data;
}

export async function fetchTableDetail(table: string): Promise<TableDetail> {
  const { data } = await api.get(`/schema/${table}`);
  return data;
}

export async function findPath(
  from: string,
  to: string,
  all = false,
  maxDepth = 6,
): Promise<PathResponse> {
  const { data } = await api.get('/path', {
    params: { from, to, all: all ? 1 : 0, maxDepth },
  });
  return data;
}

export async function fetchDerived(): Promise<unknown> {
  const { data } = await api.get('/derived');
  return data;
}

// ─── Query ────────────────────────────────────────────────────────

export async function buildQuery(
  queryDef: QueryDefinition,
): Promise<BuildResponse> {
  const { data } = await api.post('/build', queryDef);
  return data;
}

export async function executeQuery(
  sql: string,
  params: Record<string, string> = {},
  source = 'manual',
): Promise<ExecuteResponse> {
  const { data } = await api.post('/execute', { sql, params, source });
  return data;
}

export async function executeQueryDefinition(
  queryDef: QueryDefinition,
): Promise<ExecuteResponse> {
  const { data } = await api.post('/execute', {
    queryDefinition: queryDef,
    source: 'builder',
  });
  return data;
}

export async function askNl(prompt: string): Promise<NlResponse> {
  const { data } = await api.post('/nl', { prompt });
  return data;
}

export async function askSchema(
  question: string,
  selectedTable?: string | null,
): Promise<SchemaAskResponse> {
  const { data } = await api.post('/schema/ask', { question, selectedTable });
  return data;
}

// ─── Saved Queries ────────────────────────────────────────────────

export async function saveQuery(query: {
  name: string;
  description?: string;
  queryDefinition: QueryDefinition | Record<string, unknown>;
  generatedSql?: string;
  source?: 'builder' | 'nl';
  nlPrompt?: string;
  isPinned?: boolean;
}): Promise<SavedQuery> {
  const { data } = await api.post('/saved', query);
  return data;
}

export async function listSaved(): Promise<SavedQuery[]> {
  const { data } = await api.get('/saved');
  return data;
}

export async function listPinned(): Promise<SavedQuery[]> {
  const { data } = await api.get('/saved/pinned');
  return data;
}

export async function getSaved(id: number): Promise<SavedQuery> {
  const { data } = await api.get(`/saved/${id}`);
  return data;
}

export async function deleteSaved(id: number): Promise<void> {
  await api.delete(`/saved/${id}`);
}

export async function togglePin(id: number): Promise<SavedQuery> {
  const { data } = await api.post(`/saved/${id}/pin`);
  return data;
}

export async function promoteToReport(id: number): Promise<{ id: number; slug: string; name: string }> {
  const { data } = await api.post(`/saved/${id}/promote`);
  return data;
}

// ─── Health ───────────────────────────────────────────────────────

export async function checkHealth(): Promise<HealthResponse> {
  const { data } = await api.get('/health');
  return data;
}

// ─── Settings (dev) ───────────────────────────────────────────────

export async function fetchSettings(): Promise<Record<string, string>> {
  const { data } = await api.get('/settings');
  return data;
}

export async function saveSettings(
  settings: Record<string, string | undefined>,
): Promise<Record<string, string>> {
  const { data } = await api.post('/settings', settings);
  return data;
}

export async function testSettings(
  params: Record<string, unknown>,
): Promise<Record<string, any>> {
  const { data } = await api.post('/settings/test', params);
  return data;
}

// ─── Async Query Jobs ─────────────────────────────────────────────

export async function submitQuery(
  sql: string,
  params: Record<string, string> = {},
  source = 'manual',
): Promise<JobSubmitResponse> {
  const { data } = await api.post('/query/submit', { sql, params, source });
  return data;
}

export async function submitQueryDefinition(
  queryDef: QueryDefinition,
): Promise<JobSubmitResponse> {
  const { data } = await api.post('/query/submit', {
    queryDefinition: queryDef,
    source: 'builder',
  });
  return data;
}

export async function checkJobStatus(
  jobId: string,
): Promise<JobStatusResponse> {
  const { data } = await api.get(`/query/status/${jobId}`);
  return data;
}

export async function cancelJob(jobId: string): Promise<void> {
  await api.post(`/query/cancel/${jobId}`);
}

export async function listJobs(): Promise<JobStatusResponse[]> {
  const { data } = await api.get('/query/jobs');
  return data;
}

// ─── Report Templates ─────────────────────────────────────────────────

export async function listReports(): Promise<Record<string, ReportSummary[]>> {
  const { data } = await api.get('/reports');
  return data;
}

export async function getReport(id: number): Promise<ReportTemplate> {
  const { data } = await api.get(`/reports/${id}`);
  return data;
}

export async function runReport(
  id: number,
  params: Record<string, string>,
): Promise<ReportRunResponse> {
  const { data } = await api.post(`/reports/${id}/run`, { params });
  return data;
}

export async function createReport(
  template: Partial<ReportTemplate> | ReportGenerateResponse,
): Promise<ReportTemplate> {
  const { data } = await api.post('/reports', template);
  return data;
}

export async function updateReport(
  id: number,
  updates: Partial<ReportTemplate>,
): Promise<ReportTemplate> {
  const { data } = await api.put(`/reports/${id}`, updates);
  return data;
}

export async function deleteReport(id: number): Promise<void> {
  await api.delete(`/reports/${id}`);
}

export async function generateReportTemplate(
  description: string,
): Promise<ReportGenerateResponse> {
  const { data } = await api.post('/reports/generate', { description });
  return data;
}

export async function convertReportFromPhp(
  phpCode: string,
): Promise<ReportGenerateResponse> {
  const { data } = await api.post('/reports/convert', { phpCode });
  return data;
}

// ─── AI Training Hints ────────────────────────────────────────────────

export async function listTrainingHints(
  type?: string,
): Promise<TrainingHint[]> {
  const params = type ? { type } : {};
  const { data } = await api.get('/training', { params });
  return data;
}

export async function getTrainingHint(id: number): Promise<TrainingHint> {
  const { data } = await api.get(`/training/${id}`);
  return data;
}

export async function createTrainingHint(
  hint: TrainingHintInput,
): Promise<TrainingHint> {
  const { data } = await api.post('/training', hint);
  return data;
}

export async function updateTrainingHint(
  id: number,
  hint: Partial<TrainingHintInput>,
): Promise<TrainingHint> {
  const { data } = await api.put(`/training/${id}`, hint);
  return data;
}

export async function deleteTrainingHint(id: number): Promise<void> {
  await api.delete(`/training/${id}`);
}

export async function submitCorrection(
  correction: CorrectionInput,
): Promise<CorrectionResponse> {
  const { data } = await api.post('/training/correct', correction);
  return data;
}
