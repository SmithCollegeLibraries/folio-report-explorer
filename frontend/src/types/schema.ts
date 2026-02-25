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
  type: string;
  primary_key: string | null;
  remarks: string | null;
  column_count: number;
  parent_count: number;
  child_count: number;
  parent_table?: string;
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
}

/** Full table detail */
export interface TableDetail {
  name: string;
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
}

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

/** Full query definition (what gets sent to /api/build) */
export interface QueryDefinition {
  tables: string[];
  columns: SelectedColumn[];
  filters: FilterCondition[];
  joins: 'auto' | JoinEdge[];
  orderBy: SortSpec[];
  groupBy?: GroupBySpec[];
  having?: HavingCondition[];
  distinct?: boolean;
  limit: number;
}

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
}

/** NL→SQL response */
export interface NlResponse {
  sql: string;
  explanation: string;
  warnings?: string[];
}

/** Saved query */
export interface SavedQuery {
  id: number;
  name: string;
  description: string | null;
  query_definition?: Record<string, unknown>;
  generated_sql?: string;
  source: 'builder' | 'nl';
  nl_prompt?: string | null;
  is_pinned: boolean;
  created_at: string;
  updated_at: string;
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

// ─── Async Job types ──────────────────────────────────────────────

export type JobStatus = 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';

/** Response from POST /query/submit */
export interface JobSubmitResponse {
  jobId: string;
  status: JobStatus;
  sql: string;
  progressMessage: string;
  createdAt: string | null;
  startedAt: string | null;
  completedAt: string | null;
}

/** Response from GET /query/status/:id */
export interface JobStatusResponse {
  jobId: string;
  status: JobStatus;
  sql: string;
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

export type ReportCategory = 'acquisitions' | 'circulation' | 'inventory' | 'finance' | 'users' | 'other';

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
  reportName: string;
  status: 'pending';
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
