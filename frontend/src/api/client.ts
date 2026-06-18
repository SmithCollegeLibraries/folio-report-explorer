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
  AppSettings,
  Nl2SqlDashboardResponse,
  SettingsTestResponse,
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
  AuthUser,
  RefreshResponse,
  HistoryResponse,
  HistorySuggestionsResponse,
  IndexRecommendationResponse,
  DashboardResponse,
  AcrlStatistic,
  ExpenseAllocation,
  ExpenseMonitorCode,
  ExpenseMonitorRefreshResponse,
  DashboardWidgetTemplate,
} from '../types';
import { getStoredAccessToken, getStoredRefreshToken } from '../hooks/useAuth';

// Derive API URL from VITE_BASE_PATH (e.g. '/folio-report-explorer/api')
// Falls back to VITE_API_URL for dev Docker setup, or plain '/api'
const basePath = (import.meta.env.VITE_BASE_PATH || '').replace(/\/$/, '');
const apiBase = import.meta.env.VITE_API_URL || `${basePath}/api`;

const api = axios.create({
  baseURL: apiBase,
  headers: { 'Content-Type': 'application/json' },
  timeout: 60000,
});

// ── Auth interceptors ─────────────────────────────────────────────

// Request interceptor: attach JWT Bearer token
api.interceptors.request.use((config) => {
  const token = getStoredAccessToken();
  if (token) {
    config.headers = config.headers || {};
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor: handle 401 by attempting token refresh
let isRefreshing = false;
let refreshSubscribers: ((token: string) => void)[] = [];

function onRefreshed(token: string) {
  refreshSubscribers.forEach((cb) => cb(token));
  refreshSubscribers = [];
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;
    const authEnabled = import.meta.env.VITE_AUTH_ENABLED === 'true';

    // Only handle 401 when auth is enabled
    if (error.response?.status !== 401 || !authEnabled) {
      return Promise.reject(error);
    }

    // Don't retry refresh or auth endpoints
    if (originalRequest.url?.includes('/auth/refresh') || originalRequest._retry) {
      // Redirect to Shibboleth login
      window.location.href = `${basePath}/admin/authorize.php`;
      return Promise.reject(error);
    }

    if (!isRefreshing) {
      isRefreshing = true;
      const refreshToken = getStoredRefreshToken();

      if (!refreshToken) {
        window.location.href = `${basePath}/admin/authorize.php`;
        return Promise.reject(error);
      }

      try {
        const { data } = await axios.post<RefreshResponse>(`${apiBase}/auth/refresh`, {
          refreshToken,
        });

        localStorage.setItem('fre_access_token', data.accessToken);
        localStorage.setItem('fre_refresh_token', data.refreshToken);
        isRefreshing = false;
        onRefreshed(data.accessToken);
      } catch {
        isRefreshing = false;
        localStorage.removeItem('fre_access_token');
        localStorage.removeItem('fre_refresh_token');
        window.location.href = `${basePath}/admin/authorize.php`;
        return Promise.reject(error);
      }
    }

    // Queue this request until refresh completes
    return new Promise((resolve) => {
      refreshSubscribers.push((token: string) => {
        originalRequest._retry = true;
        originalRequest.headers.Authorization = `Bearer ${token}`;
        resolve(api(originalRequest));
      });
    });
  },
);

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
  dataSource: 'folio' | 'local' = 'folio',
): Promise<ExecuteResponse> {
  const { data } = await api.post('/execute', { sql, params, source, dataSource });
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

export async function askNl(
  prompt: string,
  campus?: string | null,
  includeSuggestions = true,
): Promise<NlResponse> {
  const { data } = await api.post('/nl', {
    prompt,
    campus: campus || null,
    includeSuggestions,
  });
  return data;
}

export async function fetchCampuses(): Promise<{ code: string; name: string }[]> {
  const { data } = await api.get('/campuses');
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

export async function fetchNl2SqlDashboard(): Promise<Nl2SqlDashboardResponse> {
  const { data } = await api.get('/settings/nl2sql-dashboard');
  return data;
}

// ─── Async Query Jobs ─────────────────────────────────────────────

export async function submitQuery(
  sql: string,
  params: Record<string, string> = {},
  source = 'manual',
  name?: string,
  dataSource: 'folio' | 'local' = 'folio',
  options?: { confirmed?: boolean; outputMode?: 'table' | 'file' },
): Promise<JobSubmitResponse> {
  const { data } = await api.post('/query/submit', {
    sql,
    params,
    source,
    dataSource,
    ...(options?.confirmed ? { confirmed: true } : {}),
    ...(options?.outputMode ? { outputMode: options.outputMode } : {}),
    ...(name ? { name } : {}),
  });
  return data;
}

export function getExportDownloadUrl(jobId: string): string {
  return `${apiBase}/query/export/${jobId}`;
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

// ─── Local supplementary data (admin) ────────────────────────────

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

export async function listAllocations(fiscalYear?: number): Promise<{ items: ExpenseAllocation[]; years: number[] }> {
  const { data } = await api.get('/local/allocations', { params: fiscalYear ? { fiscalYear } : {} });
  return data;
}

export async function listAllocationYears(): Promise<number[]> {
  const { data } = await api.get('/local/allocations/years');
  return data.years || [];
}

export async function upsertAllocations(
  fiscalYear: number,
  payload:
    | { code: string; amount: number }
    | { rows: Array<{ expense_class_code: string; allocation_amount: number }> }
    | { pastedData: string },
): Promise<{ success: boolean; inserted: number; updated: number }> {
  const { data } = await api.post('/local/allocations', { fiscalYear, ...payload });
  return data;
}

export async function deleteAllocation(id: number): Promise<void> {
  await api.delete(`/local/allocations/${id}`);
}

export async function copyAllocationYear(fiscalYear: number): Promise<{ success: boolean; copied: number; skipped: number; sourceYear: number }> {
  const { data } = await api.post('/local/allocations/copy-year', { fiscalYear });
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
  options?: { outputMode?: 'table' | 'file' },
): Promise<ReportRunResponse> {
  const payload: {
    params: Record<string, string>;
    outputMode?: 'table' | 'file';
  } = { params };
  if (options?.outputMode) {
    payload.outputMode = options.outputMode;
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

// ─── Auth ─────────────────────────────────────────────────────────

export async function fetchCurrentUser(): Promise<AuthUser> {
  const { data } = await api.get('/auth/me');
  return data;
}

export async function refreshAuthToken(
  refreshToken: string,
): Promise<RefreshResponse> {
  const { data } = await api.post('/auth/refresh', { refreshToken });
  return data;
}

export async function logoutAuth(): Promise<void> {
  await api.post('/auth/logout');
}

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

/** Remove a single expense class code from the current user's monitor list */
export async function removeExpenseMonitor(code: string): Promise<void> {
  await api.delete(`/expense-monitor/${code}`);
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
