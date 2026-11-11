import type { QueryDefinition } from './schema';

export type ReportRunReason = 'older_run' | 'invalid_snapshot' | 'report_retired' | 'report_deleted';

export interface ReportRunMetadata {
  reportTemplateId?: number | null;
  canSave?: boolean;
  reason?: ReportRunReason | null;
}

export type SaveQueryInput = {
  name: string;
  description?: string;
  isPinned?: boolean;
} & (
  | {
    fromJobId: string;
    queryDefinition?: never;
    generatedSql?: never;
    sqlEdited?: never;
    source?: never;
    nlPrompt?: never;
  }
  | {
    fromJobId?: never;
    queryDefinition: QueryDefinition | Record<string, unknown>;
    generatedSql?: string;
    sqlEdited?: boolean;
    source?: 'builder' | 'nl';
    nlPrompt?: string;
  }
);
