import { describe, expect, it } from 'vitest';
import type { JobStatusResponse } from '../types';
import { jobStatusToExecuteResponse } from './jobStatus';

describe('jobStatusToExecuteResponse', () => {
  it('preserves the job ID and export metadata for file results', () => {
    const status: JobStatusResponse = {
      jobId: 'job-42',
      status: 'completed',
      outputMode: 'file',
      downloadUrl: '/query/export/job-42',
      sql: 'SELECT instance_uuid FROM instance',
      dataSource: 'folio',
      progressMessage: 'Completed',
      createdAt: null,
      startedAt: null,
      completedAt: null,
      columns: ['UUID'],
      rows: [{ UUID: 'instance-1' }],
      rowCount: 200,
      executionTimeMs: 18,
      truncated: true,
      identifierSkippedCount: 2,
    };

    expect(jobStatusToExecuteResponse(status)).toEqual({
      jobId: 'job-42',
      columns: ['UUID'],
      rows: [{ UUID: 'instance-1' }],
      rowCount: 200,
      executionTimeMs: 18,
      sql: 'SELECT instance_uuid FROM instance',
      dataSource: 'folio',
      outputMode: 'file',
      downloadUrl: '/query/export/job-42',
      truncated: true,
      identifierSkippedCount: 2,
    });
  });

  it('uses fallback SQL and empty preview defaults when the status lacks inline data', () => {
    const status: JobStatusResponse = {
      jobId: 'job-43',
      status: 'completed',
      sql: '',
      progressMessage: 'Completed',
      createdAt: null,
      startedAt: null,
      completedAt: null,
    };

    expect(jobStatusToExecuteResponse(status, 'SELECT 1')).toEqual({
      jobId: 'job-43',
      columns: [],
      rows: [],
      rowCount: 0,
      executionTimeMs: 0,
      sql: 'SELECT 1',
      dataSource: undefined,
      outputMode: undefined,
      downloadUrl: undefined,
      truncated: undefined,
      identifierSkippedCount: undefined,
    });
  });
});
