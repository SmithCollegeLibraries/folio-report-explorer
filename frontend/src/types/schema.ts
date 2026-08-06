/** Schema metadata from the backend */
export interface SchemaMetadata {
  database_name: string;
  database_type: string;
  schema: string;
  scraped_at: string;
  source_url: string;
  total_tables: number;
  total_columns: number;
  total_constraints: number;
}

/** Summary info for a table in the table list */
export interface TableSummary {
  name: string;
  sql_name?: string;
  alias_name?: string | null;
  type: string;
  primary_key: string | null;
  remarks: string | null;
  column_count: number;
  parent_count: number;
  child_count: number;
  parent_table?: string;
  domain?: string;
}

/** Response from POST /api/schema/ask */
export interface SchemaAskResponse {
  answer: string;
  recommendedTables?: string[];
  sql?: string;
}

/** Full column definition */
export interface ColumnDef {
  name: string;
  type: string;
  size: number;
  nullable: boolean;
  auto_updated: boolean;
  default: string | null;
  digits: number;
  parents?: FkRef[];
  children?: FkRef[];
}

/** Foreign key reference */
export interface FkRef {
  parent_table?: string;
  parent_column?: string;
  child_table?: string;
  child_column?: string;
  foreign_key: string;
  implied: boolean;
  on_delete_cascade: boolean;
}

/** Relationship summary */
export interface Relationship {
  parent_table?: string;
  parent_column?: string;
  child_table?: string;
  child_column?: string;
  local_column: string;
  foreign_key: string;
  relationship_id?: string;
  pair_id?: string;
  label?: string;
  is_default?: boolean;
  source?: 'metadb' | 'overlay';
}

/** Schema identity explicitly requested by Query Builder callers. */
export type SchemaIdentity = 'ldlite';

/** Relationship metadata guaranteed by the canonical Builder schema view. */
export interface CanonicalRelationship extends Relationship {
  relationship_id: string;
  pair_id: string;
  from_table: string;
  from_column: string;
  to_table: string;
  to_column: string;
  label: string;
  is_default: boolean;
  source: 'metadb' | 'overlay';
}

/** Full table detail */
export interface TableDetail {
  name: string;
  sql_name?: string;
  alias_name?: string | null;
  table: {
    type: string;
    schema: string;
    remarks: string | null;
    primary_key: string | null;
    columns: ColumnDef[];
    indexes: IndexDef[];
  };
  relationships: {
    parents: Relationship[];
    children: Relationship[];
  };
}

/** Table detail guaranteed to come from the canonical Builder catalog. */
export interface CanonicalTableDetail extends Omit<TableDetail, 'relationships'> {
  relationships: {
    parents: CanonicalRelationship[];
    children: CanonicalRelationship[];
  };
}

/** Index definition */
export interface IndexDef {
  name: string;
  unique: boolean;
  columns: { name: string; ascending: boolean }[];
}

/** Join type: INNER (default) or LEFT */
export type JoinType = 'JOIN' | 'LEFT JOIN';

/** Join edge in a path */
export interface JoinEdge {
  from_table: string;
  from_column: string;
  to_table: string;
  to_column: string;
  foreign_key: string;
  join_type?: JoinType;
  relationship_id?: string;
  pair_id?: string;
}

/** Trusted relationship selection sent by the Builder instead of a raw predicate. */
export interface RelationshipSelection {
  relationship_id: string;
  join_type?: JoinType;
}

/** Join path edge guaranteed to use the canonical relationship catalog. */
export interface CanonicalJoinEdge extends JoinEdge {
  relationship_id: string;
  pair_id: string;
}

/** The only join shape accepted by a canonical Builder request. */
export type BuilderJoin = RelationshipSelection;

/** Formatted join path */
export interface JoinPath {
  chain: string[];
  hops: number;
  joins: JoinEdge[];
  sql_fragment: string;
}

/** Path finder response */
export interface PathResponse {
  from: string;
  to: string;
  path?: JoinPath;
  total_paths?: number;
  paths?: JoinPath[];
}

/** Formatted path whose edges are backed by trusted catalog identifiers. */
export interface CanonicalJoinPath extends Omit<JoinPath, 'joins'> {
  joins: CanonicalJoinEdge[];
}

/** Path response guaranteed by `identity=ldlite`. */
export interface CanonicalPathResponse extends Omit<PathResponse, 'path' | 'paths'> {
  path?: CanonicalJoinPath;
  paths?: CanonicalJoinPath[];
}

// ─── Query Builder types ──────────────────────────────────────────

/** Aggregate function type */
export type AggregateFunction = 'COUNT' | 'SUM' | 'AVG' | 'MIN' | 'MAX';

/** A column selection in the query builder */
export interface SelectedColumn {
  table: string;
  column: string;
  alias?: string;
  aggregate?: AggregateFunction | '';
}

/** A filter condition */
export interface FilterCondition {
  id: string;
  table: string;
  column: string;
  op: string;
  value: string;
  value2?: string; // For BETWEEN operator
}

/** Sort specification */
export interface SortSpec {
  id: string;
  table: string;
  column: string;
  dir: 'ASC' | 'DESC';
}

/** GROUP BY specification */
export interface GroupBySpec {
  table: string;
  column: string;
}

/** HAVING condition */
export interface HavingCondition {
  id: string;
  aggregate: AggregateFunction;
  table: string;
  column: string;
  op: string;
  value: string;
}

interface QueryDefinitionBase {
  tables: string[];
  columns: SelectedColumn[];
  filters: FilterCondition[];
  orderBy: SortSpec[];
  groupBy?: GroupBySpec[];
  having?: HavingCondition[];
  distinct?: boolean;
  limit: number;
}

/** Existing query contract retained for non-Builder and saved legacy callers. */
export interface LegacyQueryDefinition extends QueryDefinitionBase {
  schemaIdentity?: never;
  joins: 'auto' | JoinEdge[];
}

/** Canonical Builder contract: joins can only reference trusted catalog IDs. */
export interface CanonicalQueryDefinition extends QueryDefinitionBase {
  schemaIdentity: 'ldlite';
  joins: 'auto' | RelationshipSelection[];
}

/** Full query definition (what gets sent to /api/build). */
export type QueryDefinition = LegacyQueryDefinition | CanonicalQueryDefinition;

/** SQL build response */
export interface BuildResponse {
  sql: string;
  params: Record<string, string>;
  warnings?: string[];
}

/** Query execution response */
export interface ExecuteResponse {
  columns: string[];
  rows: Record<string, unknown>[];
  rowCount: number;
  executionTimeMs: number;
  sql: string;
  dataSource?: 'folio' | 'local' | 'composite';
  outputMode?: 'table' | 'file';
  downloadUrl?: string;
}

/** NL→SQL response */
export interface ClarificationOption {
  id: string;
  label: string;
  description?: string;
  recommended?: boolean;
  clarifiedPromptSuffix?: string;
  resolvedFilter?: Record<string, unknown>;
}

export interface ClarificationItem {
  term: string;
  clarificationKey: string;
  question: string;
  confidence?: string;
  reason?: string;
  inputType?: 'single_choice' | 'multi_choice' | string;
  freeTextAllowed?: boolean;
  options?: ClarificationOption[];
}

export interface ResolverTraceEntry {
  label: string;
  status: 'found' | 'no_match' | 'checked' | string;
  detail?: string;
  technicalDetail?: string;
}

export interface ExploratoryNotice {
  title?: string;
  message?: string;
  detail?: string;
  reason?: string;
}

export interface ExploratoryAssumption {
  key: string;
  label: string;
  value: string;
  explanation: string;
  correctionExample: string;
  source: 'default' | 'explicit';
}

export interface ValidationSummary {
  status: 'validated' | 'exhausted' | 'rejected';
  repairAttempts: number;
  validatorStage?: string;
  failureCategory?: string;
  message?: string;
}

export interface ExploratoryPlan {
  summary?: string;
  suggestions?: string[];
}

export interface RecoveryContext {
  originalQuestion: string;
  campus?: string | null;
}

export interface SemanticRequirementLabel {
  key: string;
  label: string;
}

export interface SemanticValidation {
  status: 'validated';
  contractVersion: number;
  checkedRequirements: SemanticRequirementLabel[];
}

export interface NlResponse {
  errorType?: string;
  generationId?: string;
  conversationId?: string;
  reviewRequired?: boolean;
  reviewNotice?: { title: string; message: string };
  sql?: string;
  explanation?: string;
  reportDisclosures?: string[];
  dataSource?: 'folio' | 'local';
  warnings?: string[];
  suggestions?: string[];
  needsClarification?: boolean;
  mode?: 'canonical' | 'exploratory' | string;
  message?: string;
  repeatabilityWarning?: string;
  exploratoryNotice?: ExploratoryNotice;
  exploratoryPlan?: ExploratoryPlan;
  assumptions?: ExploratoryAssumption[];
  repairAttempts?: number;
  validationSummary?: ValidationSummary;
  semanticContractApplicable?: boolean;
  semanticValidation?: SemanticValidation;
  recoveryItems?: string[];
  recoveryContext?: RecoveryContext;
  attemptedPlan?: string;
  clarificationType?: string;
  clarificationBatchId?: string;
  clarificationItems?: ClarificationItem[];
  resolverTrace?: ResolverTraceEntry[];
  clarificationSource?: 'model' | 'deterministic' | string;
  modelClarificationFallbackReason?: string;
  clarificationKey?: string;
  question?: string;
  inputType?: 'single_choice' | 'multi_choice' | string;
  freeTextAllowed?: boolean;
  options?: ClarificationOption[];
  route?: string;
  routeReason?: string;
}

export interface FollowUpContext {
  source: 'ask' | 'history';
  parentGenerationId?: string;
  previousPrompt?: string;
  previousSql?: string;
  previousColumns?: string[];
  previousAssumptions?: ExploratoryAssumption[];
  jobId?: string;
}

/** Saved query */
export interface SavedQuery {
  id: number;
  name: string;
  description: string | null;
  query_definition?: Record<string, unknown>;
  generated_sql?: string;
  source: 'builder' | 'nl' | 'report';
  nl_prompt?: string | null;
  is_pinned: boolean;
  is_global: boolean;
  /** UUID of the most-recent completed job; present when the query has been run at least once */
  last_job_id: string | null;
  created_at: string;
  updated_at: string;
}

/** Saved chart axis configuration */
export interface ChartConfig {
  xAxis: string;
  yAxes: string[];
}

/**
 * A dashboard item — a SavedQuery enriched with per-user position/visibility
 * data returned by GET /api/dashboard.
 */
export interface DashboardItem extends SavedQuery {
  /** 'personal' = user's own pinned query; 'global' = admin-pushed to all users */
  source_type: 'personal' | 'global';
  /** sort position (lower = higher on page) */
  position: number;
  /** How to render the card: table or a chart type */
  display_type: 'table' | 'bar' | 'line' | 'pie' | 'area';
  /** Persisted axis selection for chart display modes */
  chart_config: ChartConfig | null;
}

export interface DashboardResponse {
  items: DashboardItem[];
  /** global items the user has hidden — returned so they can be restored */
  hidden: DashboardItem[];
}

/** Health check response */
export interface HealthResponse {
  status: 'ok' | 'degraded';
  schema_loaded: boolean;
  mysql_connected: boolean;
  postgres_connected: boolean;
  schema_error?: string;
  mysql_error?: string;
  postgres_error?: string;
}

export type AiProvider = 'gemini' | 'openai';

export interface AppSettings {
  pg_host: string;
  pg_port: string;
  pg_db: string;
  pg_user: string;
  pg_pass: string;
  pg_sslmode: string;
  ai_provider: AiProvider;
  gemini_api_key: string;
  gemini_model: string;
  openai_api_key: string;
  openai_model: string;
  nl2sql_intent_mode?: boolean;
  nl2sql_primary_mode?: string;
  nl2sql_shadow_mode?: boolean;
  nl2sql_shadow_users?: string;
  nl2sql_shadow_sample_percent?: number;
  nl2sql_force_legacy?: boolean;
}

export interface ConnectionTestResult {
  connected: boolean;
  error?: string;
}

export interface PostgresConnectionTestResult extends ConnectionTestResult {
  version?: string;
}

export interface GeminiConnectionTestResult extends ConnectionTestResult {
  model?: string;
  displayName?: string;
}

export interface OpenAiConnectionTestResult extends ConnectionTestResult {
  model?: string;
}

export interface SettingsTestResponse {
  postgres?: PostgresConnectionTestResult;
  gemini?: GeminiConnectionTestResult;
  openai?: OpenAiConnectionTestResult;
}

export interface ReferenceCacheTableStatus {
  sourceTable: string;
  enabled: boolean;
  classification: string;
  rowCount: number | null;
  lastRefreshedAt: string | null;
  lastRefreshStatus: string;
  lastError: string | null;
}

export interface ReferenceCacheStatus {
  available: boolean;
  enabledTables: number;
  activeRows: number;
  failedTables: number;
  manualReviewTables: number;
  disabledCacheableTables: number;
  lastRefreshedAt: string | null;
  tables: ReferenceCacheTableStatus[];
  error?: string;
}

export interface ReferenceCacheCandidateSummary {
  classification: string;
  sourceSchema: string;
  tableCount: number;
}

export interface ReferenceCacheCandidate {
  sourceTable: string;
  sourceSchema: string;
  classification: string;
  estimatedRows: number | null;
  totalBytes: number | null;
}

export interface ReferenceCacheCandidatesResponse {
  available: boolean;
  summaryBySchema: ReferenceCacheCandidateSummary[];
  candidates: ReferenceCacheCandidate[];
  error?: string;
}

export type ReferenceCacheCandidateDecision = 'enable' | 'disable' | 'reject';

export interface ReferenceCacheCandidateReviewResponse {
  sourceTable: string;
  enabled: boolean;
  classification: string;
  estimatedRows: number | null;
  totalBytes: number | null;
  error?: string;
}

export interface ReferenceCacheRefreshTableResponse {
  sourceTable: string;
  rowCount: number;
  lastRefreshStatus: string;
  error?: string;
}

// ─── Async Job types ──────────────────────────────────────────────

export type JobStatus = 'pending' | 'pending_export' | 'running' | 'cancelling' | 'completed' | 'failed' | 'cancelled';

/** Response from POST /query/submit */
export interface JobSubmitResponse {
  jobId: string;
  status: JobStatus;
  requiresConfirmation?: boolean;
  estimatedRows?: number;
  estimatedCost?: number;
  outputMode?: 'table' | 'file';
  sql: string;
  dataSource?: 'folio' | 'local' | 'composite';
  progressMessage: string;
  createdAt: string | null;
  startedAt: string | null;
  completedAt: string | null;
}

/** Response from GET /query/status/:id */
export interface JobStatusResponse {
  jobId: string;
  status: JobStatus;
  outputMode?: 'table' | 'file';
  estimatedRows?: number;
  estimatedCost?: number;
  downloadUrl?: string;
  sql: string;
  dataSource?: 'folio' | 'local' | 'composite';
  progressMessage: string;
  createdAt: string | null;
  startedAt: string | null;
  completedAt: string | null;
  columns?: string[];
  rows?: Record<string, unknown>[];
  rowCount?: number;
  executionTimeMs?: number;
  error?: string;
}

// ─── Report Template types ────────────────────────────────────────

export type ReportCategory = 'acquisitions' | 'circulation' | 'inventory' | 'finance' | 'users' | 'cataloging' | 'other';

/** Parameter definition for a report template */
export interface ReportParam {
  name: string;
  type: 'date' | 'text' | 'select' | 'number' | 'boolean' | 'list';
  label: string;
  required: boolean;
  default: string;
  resolvedDefault: string;
  placeholder?: string;
  description?: string;
  wrap?: 'like';
  options_sql?: string;
  input_mode?: 'numeric';
  pattern?: '[0-9]{3}';
  max_length?: 3;
}

/** Report template summary (list view) */
export interface ReportSummary {
  id: number;
  slug: string;
  name: string;
  description: string;
  category: ReportCategory;
  parameterCount: number;
  defaultLimit: number;
  createdBy: 'manual' | 'ai';
  createdAt: string;
}

/** Full report template detail */
export interface ReportTemplate {
  id: number;
  slug: string;
  name: string;
  description: string;
  helpText?: string | null;
  category: ReportCategory;
  sqlTemplate: string;
  parameters: ReportParam[];
  defaultLimit: number;
  isActive: boolean;
  createdBy: 'manual' | 'ai';
  createdAt: string;
  updatedAt: string;
  selectOptions?: Record<string, { value: string; label: string }[]>;
}

/** Response from POST /reports/generate (AI-generated template preview) */
export interface ReportGenerateResponse {
  slug: string;
  name: string;
  description: string;
  category: ReportCategory;
  sqlTemplate: string;
  parameters: ReportParam[];
  defaultLimit: number;
  createdBy: 'ai';
}

/** Response from POST /reports/:id/run */
export interface ReportRunResponse {
  jobId: string;
  status: JobStatus;
  reportName: string;
  outputMode?: 'table' | 'file';
  dataSource?: 'folio' | 'local' | 'composite';
  progressMessage?: string;
  createdAt?: string | null;
  startedAt?: string | null;
  completedAt?: string | null;
}

// ─── AI Training types ────────────────────────────────────────────

export type TrainingHintType = 'table_description' | 'vocabulary' | 'example' | 'correction';

export interface TrainingHint {
  id: number;
  type: TrainingHintType;
  hint_key: string | null;
  hint_value: string | null;
  example_question: string | null;
  example_sql: string | null;
  original_sql: string | null;
  notes: string | null;
  is_active: number;
  created_at: string;
  updated_at: string;
}

export interface TrainingHintInput {
  type: TrainingHintType;
  hintKey?: string | null;
  hintValue?: string | null;
  exampleQuestion?: string | null;
  exampleSql?: string | null;
  originalSql?: string | null;
  notes?: string | null;
  isActive?: number;
}

export interface CorrectionInput {
  prompt: string;
  originalSql: string;
  correctedSql: string;
  notes?: string;
}

export interface CorrectionResponse {
  correction: TrainingHint;
  message: string;
}

// ─── Auth types ───────────────────────────────────────────────────

export interface AuthUser {
  id: number;
  smithId: string;
  username: string;
  firstName: string | null;
  lastName: string | null;
  displayName: string;
  affiliation: string | null;
  email: string | null;
  role: 'admin' | 'user';
  isApproved: boolean;
  receiveNotifications: boolean;
  defaultCampus: string;
  lastLogin: string | null;
  createdAt: string;
}

export interface JwtPayload {
  iss: string;
  sub: number;
  iat: number;
  exp: number;
  type: 'access' | 'refresh';
  user?: {
    id: number;
    username: string;
    firstName: string | null;
    lastName: string | null;
    role: 'admin' | 'user';
    defaultCampus?: string;
  };
}

export interface RefreshResponse {
  accessToken: string;
  refreshToken: string;
  user: AuthUser;
}

// ─── Query history types ──────────────────────────────────────────

export interface HistoryItem {
  jobId: string;
  name: string | null;
  status: JobStatus;
  sql: string;
  source: string;
  dataSource: string;
  progressMessage: string | null;
  rowCount: number;
  executionTimeMs: number;
  errorMessage: string | null;
  createdAt: string;
  startedAt: string | null;
  completedAt: string | null;
  runBy: string | null;
  canDelete: boolean;
  reviewAdvisory?: {
    state: 'cautioned' | 'superseded';
    message: string;
    supersededByJobId?: string;
  };
}

export interface HistoryResponse {
  total: number;
  offset: number;
  limit: number;
  items: HistoryItem[];
}

export interface HistorySuggestionsResponse {
  jobId: string;
  promptSeed: string;
  suggestions: string[];
  suggestionSource: 'gemini' | 'heuristic';
  warnings?: string[];
}

// ─── Administrator Ask AI report reviews ───────────────────────────

export type ReportReviewStatus = 'pending' | 'in_review' | 'resolved' | 'dismissed';
export type ReportReviewDisposition =
  | 'acceptable'
  | 'assumption_change'
  | 'deterministic_candidate'
  | 'generation_defect'
  | 'data_unavailable'
  | 'specialist_interpretation';
export type ReportReviewAdvisoryState = 'none' | 'cautioned' | 'superseded';

export interface ReportReviewFilters {
  status: ReportReviewStatus;
  disposition?: ReportReviewDisposition | '';
  limit?: number;
  offset?: number;
}

export interface ReportReviewSummary {
  id: string;
  generationId: string;
  status: ReportReviewStatus;
  disposition: ReportReviewDisposition | null;
  advisoryState: ReportReviewAdvisoryState;
  supersededByJobId: string | null;
  reviewedBy: number | null;
  claimedAt: string | null;
  resolvedAt: string | null;
  createdAt: string;
  updatedAt: string;
  question: string;
  queryJobId: string | null;
  userId: number | null;
  executionMode: string | null;
  route: string | null;
  routeReason: string | null;
  validationStatus: string | null;
  reviewReasons: string[];
}

export interface ReportReviewListResponse {
  items: ReportReviewSummary[];
  pagination: {
    limit: number;
    offset: number;
    total: number;
  };
}

export interface ReportReviewDetail extends ReportReviewSummary {
  administratorNotes: string | null;
  conversationId: string;
  parentGenerationId: string | null;
  followUpContext: Record<string, unknown> | null;
  responseMode: string | null;
  generatedSql: string | null;
  sqlHash: string | null;
  assumptions: unknown[];
  userNotice: Record<string, unknown> | null;
  confidenceEvidence: Record<string, unknown>;
  initialStructure: Record<string, unknown> | null;
  finalStructure: Record<string, unknown> | null;
  provenance: Record<string, unknown>;
  generationCreatedAt: string;
  linkedAt: string | null;
}

export interface ReportReviewUpdate {
  status: 'resolved' | 'dismissed';
  disposition: ReportReviewDisposition;
  notes?: string;
  advisoryState?: ReportReviewAdvisoryState;
  supersededByJobId?: string;
  takeover?: boolean;
}

export interface QueryReuseCandidate {
  jobId: string;
  previousPrompt: string;
  sql: string;
  dataSource: string;
  score: number;
  matchReasons: string[];
  rowCount: number | null;
  executionTimeMs: number | null;
  completedAt: string | null;
}

export interface QueryReuseCandidateRequest {
  prompt: string;
  dataSource?: string;
  resolvedContext?: Record<string, string>;
}

export interface QueryReuseCandidateResponse {
  match: QueryReuseCandidate | null;
}

export interface QueryReuseDecisionInput {
  decision: 'accepted' | 'edited' | 'bypassed';
  candidateJobId?: string;
  prompt?: string;
}

export interface IndexRecommendationEvidence {
  patternIds?: string[];
  estimatedImpact?: 'high' | 'medium' | 'low';
}

export interface IndexRecommendation {
  table: string;
  columns: string[];
  indexType: 'btree' | 'gin' | 'gist' | 'hash';
  confidence: 'high' | 'medium' | 'low';
  reason: string;
  evidence: IndexRecommendationEvidence | null;
  createIndexSql: string;
}

export interface QueryPatternSummary {
  patternId: string;
  sqlHash: string;
  sampleSql: string;
  source: string;
  executions: number;
  avgExecutionMs: number;
  maxExecutionMs: number;
  avgRowCount: number | null;
  tables: string[];
  sampleJobIds: string[];
  lastSeenAt: string;
}

export interface IndexRecommendationWorkload {
  logsAnalyzed: number;
  eligibleLogs: number;
  uniqueQueryPatterns: number;
  tables: string[];
  queryPatterns: QueryPatternSummary[];
}

export interface IndexRecommendationResponse {
  generatedAt: string;
  summary: string;
  workload: IndexRecommendationWorkload;
  recommendations: IndexRecommendation[];
  recommendationSource?: 'gemini' | 'heuristic' | 'none';
  notes: string[];
  warnings?: string[];
  model?: string | null;
  promptVersion?: string | null;
  error?: string;
}

export interface AcrlStatistic {
  id: number;
  category: string;
  subcategory: string;
  year: number;
  value: number | null;
  notes: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface ExpenseAllocation {
  id: number;
  fiscal_year: number;
  expense_class_code: string;
  allocation_amount: number;
  created_at?: string;
  updated_at?: string;
}

// ─── Expense Class Monitor types ─────────────────────────────────

/** An SC-prefixed expense class available for monitoring */
export interface ExpenseMonitorCode {
  code: string;
  name: string;
}

/** Response from POST /expense-monitor/refresh */
export interface ExpenseMonitorRefreshResponse {
  jobId: string;
  fiscalYear: number;
  codes: string[];
  status: 'pending';
}

// ─── Dashboard Widget Gallery types ──────────────────────────────

/** A required setup param surfaced by the backend so the user can configure it before adding. */
export interface WidgetSetupParam {
  name: string;
  label: string;
  type: 'text' | 'select' | 'number';
  required: boolean;
  placeholder: string;
  description: string;
  options: Array<{ value?: string; label?: string; [key: string]: unknown }>;
}

/** A widget template from the gallery catalog. */
export interface DashboardWidgetTemplate {
  id: number;
  name: string;
  description: string;
  category: string;
  icon: string;
  widget_type: 'report' | 'budget_monitor';
  report_template_id: number | null;
  default_params: Record<string, string> | null;
  sort_order: number;
  is_enabled?: boolean;
  /** Whether the current user has already added this widget */
  is_added: boolean;
  /** Required params that the user must fill in before adding (report widgets only) */
  setup_params: WidgetSetupParam[];
}
