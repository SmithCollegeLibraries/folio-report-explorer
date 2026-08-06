import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cancelJob, checkJobStatus } from '../api/client';
import { useJobPolling } from './useJobPolling';

vi.mock('../api/client', () => ({
  cancelJob: vi.fn(),
  checkJobStatus: vi.fn(),
}));

const pendingStatus = {
  jobId: 'job-1',
  status: 'pending' as const,
  sql: 'SELECT 1',
  progressMessage: 'Queued',
  createdAt: '2026-07-19T10:00:00Z',
  startedAt: null,
  completedAt: null,
};

describe('useJobPolling cancellation', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.mocked(checkJobStatus).mockResolvedValue(pendingStatus);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  it('keeps polling while the server is cancelling and stops at cancelled', async () => {
    vi.mocked(cancelJob).mockResolvedValue({
      ...pendingStatus,
      status: 'cancelling',
      progressMessage: 'Cancelling…',
    });

    const { result } = renderHook(() => useJobPolling('job-1'));
    await act(async () => { await Promise.resolve(); });

    await act(async () => {
      await result.current.cancel();
    });

    expect(result.current.job?.status).toBe('cancelling');
    expect(result.current.job?.progressMessage).toBe('Cancelling…');
    expect(result.current.isRunning).toBe(true);
    expect(result.current.error).toBeNull();

    vi.mocked(checkJobStatus).mockResolvedValue({
      ...pendingStatus,
      status: 'cancelled',
      progressMessage: 'Cancelled by user',
      completedAt: '2026-07-19T10:00:05Z',
    });

    await act(async () => {
      await vi.advanceTimersByTimeAsync(2000);
    });

    expect(result.current.job?.status).toBe('cancelled');
    expect(result.current.isRunning).toBe(false);
    expect(result.current.error).toBe('Query was cancelled');
  });

  it('keeps a completed report truncation flag in its table results', async () => {
    vi.mocked(checkJobStatus).mockResolvedValue({
      ...pendingStatus,
      status: 'completed',
      outputMode: 'table',
      columns: ['Instance UUID'],
      rows: [{ 'Instance UUID': 'instance-1' }],
      rowCount: 100000,
      executionTimeMs: 18,
      truncated: true,
    });

    const { result } = renderHook(() => useJobPolling('job-1'));
    await act(async () => { await Promise.resolve(); });

    expect(result.current.results?.truncated).toBe(true);
  });

  it('keeps a completed report truncation flag and download metadata in file results', async () => {
    vi.mocked(checkJobStatus).mockResolvedValue({
      ...pendingStatus,
      status: 'completed',
      outputMode: 'file',
      columns: ['UUID'],
      rows: [{ UUID: 'instance-1' }],
      rowCount: 100000,
      executionTimeMs: 18,
      downloadUrl: '/api/query/export/job-1',
      truncated: true,
    });

    const { result } = renderHook(() => useJobPolling('job-1'));
    await act(async () => { await Promise.resolve(); });

    expect(result.current.results).toMatchObject({
      outputMode: 'file',
      downloadUrl: '/api/query/export/job-1',
      truncated: true,
    });
  });
});
