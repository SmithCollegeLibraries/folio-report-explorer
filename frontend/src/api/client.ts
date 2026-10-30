import axios from 'axios';
import type {
  TableSummary,
  SchemaMetadata,
  TableDetail,
  PathResponse,
  BuildResponse,
  NlResponse,
  SchemaAskResponse,
  SavedQuery,
  AppSettings,
  SettingsTestResponse,
  ReferenceCacheStatus,
  ReferenceCacheCandidatesResponse,
  ReferenceCacheCandidateDecision,
  ReferenceCacheCandidateReviewResponse,
  ReferenceCacheRefreshTableResponse,
  QueryDefinition,
  JobSubmitResponse,
  JobStatusResponse,
  ReportSummary,
  ReportTemplate,
  ReportGenerateResponse,
  ReportExportKind,
  ReportRunResponse,
  TrainingHint,
  TrainingHintInput,
  CorrectionInput,
  CorrectionResponse,
  AuthUser,
  HistoryResponse,
  HistorySuggestionsResponse,
  QueryReuseCandidateRequest,
  QueryReuseCandidateResponse,
  QueryFeedbackResponse,
  QueryFeedbackReplacementInput,
  QueryMemorySignal,
  QueryReuseDecisionInput,
  IndexRecommendationResponse,
  DashboardResponse,
  ExpenseMonitorCode,
  ExpenseMonitorRefreshResponse,
  DashboardWidgetTemplate,
  FollowUpContext,
  SchemaIdentity,
  CanonicalTableDetail,
  CanonicalPathResponse,
  ReportReviewFilters,
  ReportReviewListResponse,
  ReportReviewDetail,
  ReportReviewUpdate,
  QueryMemoryItem,
  QueryMemoryListResponse,
  QueryMemoryStatus,
  QueryMemorySuppressionResponse,
} from '../types';
import { configureAuthInterceptors } from './authInterceptors';

// Derive API URL from VITE_BASE_PATH (e.g. '/folio-report-explorer/api')
// Falls back to VITE_API_URL for dev Docker setup, or plain '/api'
const basePath = (import.meta.env.VITE_BASE_PATH || '').replace(/\/$/, '');
const apiBase = import.meta.env.VITE_API_URL || `${basePath}/api`;

export const api = axios.create({
  baseURL: apiBase,
  headers: { 'Content-Type': 'application/json' },
  timeout: 300000,
});

/** Return only trusted string field messages from a governed report error. */
export function extractReportFieldErrors(error: unknown): Record<string, string> {
  if (!axios.isAxiosError(error)) return {};
  const value = error.response?.data?.fieldErrors;
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  return Object.fromEntries(
    Object.entries(value).filter(
      (entry): entry is [string, string] =>
        typeof entry[0] === 'string' && typeof entry[1] === 'string',
    ),
  );
}

configureAuthInterceptors(api, apiBase, basePath);

// ─── Schema ───────────────────────────────────────────────────────

export async function fetchSchema(tables?: string[], identity?: SchemaIdentity): Promise<{
  metadata: SchemaMetadata;
  tables: Record<string, TableSummary>;
}> {
  const params: { tables?: string; identity?: SchemaIdentity } = {};
  if (tables) params.tables = tables.join(',');
  if (identity) params.identity = identity;
  const { data } = await api.get('/schema', { params });
  return data;
}

export function fetchTableDetail(
  table: string,
  identity: 'ldlite',
): Promise<CanonicalTableDetail>;
export function fetchTableDetail(
  table: string,
  identity?: undefined,
): Promise<TableDetail>;
export async function fetchTableDetail(
  table: string,
  identity?: SchemaIdentity,
): Promise<TableDetail | CanonicalTableDetail> {
  const { data } = identity
    ? await api.get(`/schema/${table}`, { params: { identity } })
    : await api.get(`/schema/${table}`);
  return data;
}

export function findPath(
  from: string,
  to: string,
  all: boolean | undefined,
  maxDepth: number | undefined,
  identity: 'ldlite',
): Promise<CanonicalPathResponse>;
export function findPath(
  from: string,
  to: string,
  all?: boolean,
  maxDepth?: number,
  identity?: undefined,
): Promise<PathResponse>;
export async function findPath(
  from: string,
  to: string,
  all = false,
  maxDepth = 6,
  identity?: SchemaIdentity,
): Promise<PathResponse | CanonicalPathResponse> {
  const { data } = await api.get('/path', {
    params: {
      from,
      to,
      all: all ? 1 : 0,
      maxDepth,
      ...(identity ? { identity } : {}),
    },
  });
  return data;
}

// ─── Query ────────────────────────────────────────────────────────

export async function buildQuery(
  queryDef: QueryDefinition,
): Promise<BuildResponse> {
  const { data } = await api.post('/build', queryDef);
  return data;
}

export async function askNl(
  prompt: string,
  campus?: string | null,
  includeSuggestions = true,
  followUpContext?: FollowUpContext | null,
  allowExploratory = false,
  parentGenerationId?: string | null,
): Promise<NlResponse> {
  const { data } = await api.post('/nl', {
    prompt,
    campus: campus || null,
    includeSuggestions,
    allowExploratory,
    ...(parentGenerationId ? { parentGenerationId } : {}),
    ...(followUpContext ? { followUpContext } : {}),
  });
  return data;
}

export async function saveQueryFeedback(input: {
  generationId: string;
  queryJobId: string;
  resultAccuracy: 'accurate' | 'inaccurate' | 'unsure';
  feedbackNote?: string | null;
}): Promise<QueryFeedbackResponse> {
  const { data } = await api.post('/query-feedback', input);
  return data;
}

export async function replaceQueryFeedback(
  feedbackId: number,
  input: QueryFeedbackReplacementInput,
): Promise<NlResponse> {
  const { data } = await api.post(`/query-feedback/${feedbackId}/replacement`, input);
  return data;
}

export async function recordQueryMemorySignal(input: {
  generationId: string;
  queryJobId: string;
  signal: QueryMemorySignal;
}): Promise<{ ok: boolean; signal: QueryMemorySignal; count: number }> {
  const { data } = await api.post('/query/memory-signal', input);
  return data;
}

export async function saveClarificationResolution(input: {
  originalQuestion: string;
  clarificationKey: string;
  clarificationBatchId?: string | null;
  term?: string | null;
  detectedTerms?: string[];
  options?: unknown[];
  selectedOptionIds?: string[];
  freeTextResponse?: string | null;
  resolvedFilter?: Record<string, unknown> | null;
  selectedSourceTable?: string | null;
  selectedSourceId?: string | null;
  selectedValue?: string | null;
  confidence?: string | null;
  promotionStatus?: string | null;
  items?: Array<{
    term?: string | null;
    clarificationKey: string;
    confidence?: string | null;
    options?: unknown[];
    selectedOptionIds?: string[];
    freeTextResponse?: string | null;
    resolvedFilter?: Record<string, unknown> | null;
    selectedSourceTable?: string | null;
    selectedSourceId?: string | null;
    selectedValue?: string | null;
    promotionStatus?: string | null;
  }>;
  generatedSql?: string | null;
  resultStatus?: string | null;
}): Promise<{ id?: number; ids?: number[]; message: string }> {
  const { data } = await api.post('/clarifications/resolve', input);
  return data;
}

export async function saveCampusPreference(campus: string): Promise<void> {
  await api.patch('/user/campus', { campus });
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
  sqlEdited?: boolean;
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

export async function deleteSaved(id: number): Promise<void> {
  await api.delete(`/saved/${id}`);
}

export async function togglePin(id: number): Promise<SavedQuery> {
  const { data } = await api.post(`/saved/${id}/pin`);
  return data;
}

export async function promoteToReport(id: number): Promise<{
  data: { id: number; slug: string; name: string };
  status: number;
}> {
  const { data, status } = await api.post(`/saved/${id}/promote`);
  return { data, status };
}

// ─── Settings (dev) ───────────────────────────────────────────────

export async function fetchSettings(): Promise<Partial<AppSettings>> {
  const { data } = await api.get('/settings');
  return data;
}

export async function saveSettings(
  settings: Partial<AppSettings>,
): Promise<Partial<AppSettings>> {
  const { data } = await api.post('/settings', settings);
  return data;
}

export async function testSettings(
  params: Record<string, unknown>,
): Promise<SettingsTestResponse> {
  const { data } = await api.post('/settings/test', params);
  return data;
}

export async function fetchReferenceCacheStatus(): Promise<ReferenceCacheStatus> {
  const { data } = await api.get('/reference-cache/status');
  return data;
}

export async function fetchReferenceCacheCandidates(): Promise<ReferenceCacheCandidatesResponse> {
  const { data } = await api.get('/reference-cache/candidates');
  return data;
}

export async function reviewReferenceCacheCandidate(input: {
  sourceTable: string;
  decision: ReferenceCacheCandidateDecision;
}): Promise<ReferenceCacheCandidateReviewResponse> {
  const { data } = await api.post('/reference-cache/candidates/review', input);
  return data;
}

export async function refreshReferenceCacheTable(input: {
  sourceTable: string;
}): Promise<ReferenceCacheRefreshTableResponse> {
  const { data } = await api.post('/reference-cache/refresh', input);
  return data;
}

// ─── Async Query Jobs ─────────────────────────────────────────────

export async function submitQuery(
  sql: string,
  params: Record<string, string> = {},
  source = 'manual',
  name?: string,
  dataSource: 'folio' | 'local' = 'folio',
  options?: {
    confirmed?: boolean;
    outputMode?: 'table' | 'file';
    queryReuse?: {
      candidateJobId: string;
      edited: boolean;
      score?: number;
    };
    resolvedContext?: Record<string, string>;
    generationId?: string;
  },
): Promise<JobSubmitResponse> {
  const { data } = await api.post('/query/submit', {
    sql,
    params,
    source,
    dataSource,
    ...(options?.confirmed ? { confirmed: true } : {}),
    ...(options?.outputMode ? { outputMode: options.outputMode } : {}),
    ...(options?.queryReuse ? { queryReuse: options.queryReuse } : {}),
    ...(options?.resolvedContext ? { resolvedContext: options.resolvedContext } : {}),
    ...(options?.generationId ? { generationId: options.generationId } : {}),
    ...(name ? { name } : {}),
  });
  return data;
}

export async function recordQueryReuseDecision(input: QueryReuseDecisionInput): Promise<{ ok: boolean }> {
  const { data } = await api.post('/query/reuse-decision', input);
  return data;
}

export async function downloadExportCsv(jobId: string): Promise<void> {
  const response = await api.get(`/query/export/${jobId}`, { responseType: 'blob' });
  const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8;' });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.setAttribute('download', `${jobId}.csv`);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

export async function checkJobStatus(
  jobId: string,
): Promise<JobStatusResponse> {
  const { data } = await api.get(`/query/status/${jobId}`);
  return data;
}

export async function cancelJob(jobId: string): Promise<JobStatusResponse> {
  const { data } = await api.post(`/query/cancel/${jobId}`);
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
  options?: { outputMode?: 'table' | 'file'; exportKind?: ReportExportKind },
): Promise<ReportRunResponse> {
  const payload: {
    params: Record<string, string>;
    outputMode?: 'table' | 'file';
    exportKind?: ReportExportKind;
  } = { params };
  if (options?.outputMode) {
    payload.outputMode = options.outputMode;
  }
  if (options?.exportKind) {
    payload.exportKind = options.exportKind;
  }
  const { data } = await api.post(`/reports/${id}/run`, payload);
  return data;
}

export async function createReport(
  template: Partial<ReportTemplate> | ReportGenerateResponse,
): Promise<ReportTemplate> {
  const { data } = await api.post('/reports', template);
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

// ─── Auth ─────────────────────────────────────────────────────────

// ─── User Management (admin) ─────────────────────────────────────

export async function listUsers(): Promise<AuthUser[]> {
  const { data } = await api.get('/users');
  return data;
}

export async function approveUser(
  id: number,
  approved: boolean,
): Promise<AuthUser> {
  const { data } = await api.put(`/users/${id}/approve`, { approved });
  return data;
}

export async function changeUserRole(
  id: number,
  role: 'admin' | 'user',
): Promise<AuthUser> {
  const { data } = await api.put(`/users/${id}/role`, { role });
  return data;
}

export async function deleteUser(id: number): Promise<void> {
  await api.delete(`/users/${id}`);
}

export async function toggleUserNotifications(
  id: number,
  receive: boolean,
): Promise<AuthUser> {
  const { data } = await api.put(`/users/${id}/notifications`, { receive });
  return data;
}

// ─── Query History ────────────────────────────────────────────────

export async function fetchQueryHistory(
  limit = 50,
  offset = 0,
  status?: string,
  mine = false,
): Promise<HistoryResponse> {
  const params: Record<string, string | number> = { limit, offset };
  if (status && status !== 'all') params.status = status;
  if (mine) params.mine = 1;
  const { data } = await api.get('/query/history', { params });
  return data;
}

export async function fetchHistorySuggestions(jobId: string): Promise<HistorySuggestionsResponse> {
  const { data } = await api.post(`/query/history/${jobId}/suggestions`);
  return data;
}

export async function fetchQueryReuseCandidate(
  payload: QueryReuseCandidateRequest,
): Promise<QueryReuseCandidateResponse> {
  const { data } = await api.post('/query/reuse-candidate', payload);
  return data;
}

export async function renameHistoryJob(jobId: string, name: string): Promise<{ jobId: string; name: string | null }> {
  const { data } = await api.patch(`/query/history/${jobId}`, { name });
  return data;
}

export async function deleteHistoryJob(jobId: string): Promise<void> {
  await api.delete(`/query/history/${jobId}`);
}

export async function fetchIndexRecommendations(payload: {
  days?: number;
  maxLogs?: number;
  maxPatterns?: number;
} = {}): Promise<IndexRecommendationResponse> {
  const { data } = await api.post('/query/index-recommendations', payload);
  return data;
}

// ─── Per-user Dashboard ───────────────────────────────────────────

export async function fetchDashboard(): Promise<DashboardResponse> {
  const { data } = await api.get('/dashboard');
  return data;
}

export async function reorderDashboard(order: number[]): Promise<void> {
  await api.patch('/dashboard/reorder', { order });
}

export async function hideDashboardItem(savedQueryId: number): Promise<void> {
  await api.post(`/dashboard/${savedQueryId}/hide`);
}

export async function showDashboardItem(savedQueryId: number): Promise<void> {
  await api.post(`/dashboard/${savedQueryId}/show`);
}

export async function toggleGlobal(savedQueryId: number): Promise<SavedQuery> {
  const { data } = await api.patch(`/saved/${savedQueryId}/global`);
  return data;
}

/** Enqueue a fresh run for a dashboard card; returns the new jobId to poll */
export async function refreshDashboardCard(savedQueryId: number): Promise<{ jobId: string }> {
  const { data } = await api.post(`/dashboard/${savedQueryId}/refresh`);
  return data;
}

/** Persist per-user display mode (table / chart type) and optional axis config */
export async function saveDashboardDisplay(
  savedQueryId: number,
  displayType: 'table' | 'bar' | 'line' | 'pie' | 'area',
  chartConfig?: { xAxis: string; yAxes: string[] } | null,
): Promise<void> {
  await api.patch(`/dashboard/${savedQueryId}/display`, { displayType, chartConfig: chartConfig ?? null });
}

// ─── Expense Class Monitor ────────────────────────────────────────

/** List all SC-prefixed expense classes available in FOLIO (for the selector) */
export async function fetchExpenseMonitorCodes(): Promise<ExpenseMonitorCode[]> {
  const { data } = await api.get('/expense-monitor/codes');
  return data.codes ?? [];
}

/** Get the current user's monitored expense class codes */
export async function fetchExpenseMonitors(): Promise<string[]> {
  const { data } = await api.get('/expense-monitor');
  return data.codes ?? [];
}

/** Replace the current user's monitored codes (full replace) */
export async function saveExpenseMonitors(codes: string[]): Promise<string[]> {
  const { data } = await api.post('/expense-monitor', { codes });
  return data.codes ?? [];
}

/**
 * Enqueue a composite budget-vs-actual job scoped to the user's monitored codes.
 * Returns {jobId} for polling via checkJobStatus().
 */
export async function refreshExpenseMonitor(fiscalYear?: number): Promise<ExpenseMonitorRefreshResponse> {
  const body = fiscalYear ? { fiscalYear } : {};
  const { data } = await api.post('/expense-monitor/refresh', body);
  return data;
}

// ─── Dashboard Widget Gallery ─────────────────────────────────────────────────

/** Fetch the full widget catalog for the current user (includes is_added flag). */
export async function fetchDashboardWidgets(): Promise<DashboardWidgetTemplate[]> {
  const { data } = await api.get('/dashboard/widgets');
  return data.widgets ?? [];
}

/** Add a widget to the current user's dashboard. Pass any required setup params. */
export async function addDashboardWidget(
  id: number,
  params: Record<string, string> = {},
): Promise<{ savedQueryId: number | null }> {
  const { data } = await api.post(`/dashboard/widgets/${id}/add`, { params });
  return data;
}

/** Remove a widget from the current user's dashboard. */
export async function removeDashboardWidget(id: number): Promise<void> {
  await api.delete(`/dashboard/widgets/${id}/remove`);
}

/** Admin: create a new widget template. */
export async function createAdminWidget(
  payload: Partial<DashboardWidgetTemplate> & { default_params_json?: string },
): Promise<DashboardWidgetTemplate> {
  const { data } = await api.post('/admin/dashboard-widgets', payload);
  return data;
}

/** Admin: update an existing widget template. */
export async function updateAdminWidget(
  id: number,
  payload: Partial<DashboardWidgetTemplate> & { default_params_json?: string },
): Promise<DashboardWidgetTemplate> {
  const { data } = await api.put(`/admin/dashboard-widgets/${id}`, payload);
  return data;
}

/** Admin: soft-delete (disable) a widget template. */
export async function deleteAdminWidget(id: number): Promise<void> {
  await api.delete(`/admin/dashboard-widgets/${id}`);
}

// ─── Administrator Ask AI report reviews ───────────────────────────

export const fetchReportReviews = async (params: ReportReviewFilters): Promise<ReportReviewListResponse> =>
  (await api.get('/admin/report-reviews', { params })).data;

export const fetchReportReview = async (id: string): Promise<ReportReviewDetail> =>
  (await api.get(`/admin/report-reviews/${id}`)).data;

export const claimReportReview = async (id: string): Promise<ReportReviewDetail> =>
  (await api.post(`/admin/report-reviews/${id}/claim`)).data;

export const updateReportReview = async (id: string, input: ReportReviewUpdate): Promise<ReportReviewDetail> =>
  (await api.patch(`/admin/report-reviews/${id}`, input)).data;

export const fetchQueryMemory = async (
  status: QueryMemoryStatus = 'all',
  limit = 25,
  offset = 0,
): Promise<QueryMemoryListResponse> =>
  (await api.get('/admin/query-memory', { params: { status, limit, offset } })).data;

export const updateQueryMemoryReuseApproval = async (
  id: number,
  approved: boolean,
): Promise<QueryMemoryItem> =>
  (await api.patch(`/admin/query-feedback/${id}/reuse-approval`, { approved })).data;

export const clearQueryMemorySuppression = async (id: number): Promise<QueryMemorySuppressionResponse> =>
  (await api.patch(`/admin/query-feedback/${id}/suppression`, { suppressed: false })).data;
