import type { ExecuteResponse, JobStatusResponse } from '../types';

export function jobStatusToExecuteResponse(
  status: JobStatusResponse,
  fallbackSql = '',
): ExecuteResponse {
  return {
    jobId: status.jobId,
    columns: status.columns ?? [],
    rows: status.rows ?? [],
    rowCount: status.rowCount ?? 0,
    executionTimeMs: status.executionTimeMs ?? 0,
    sql: status.sql || fallbackSql,
    dataSource: status.dataSource,
    outputMode: status.outputMode,
    downloadUrl: status.downloadUrl,
    truncated: status.truncated,
    identifierSkippedCount: status.identifierSkippedCount,
  };
}
