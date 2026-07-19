import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchQueryHistory } from '../api/client';
import type { HistoryItem, HistoryResponse } from '../types';
import { useHistoryData } from './useHistoryData';

vi.mock('../api/client', () => ({
  fetchQueryHistory: vi.fn(),
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

function historyItem(jobId: string): HistoryItem {
  return {
    jobId,
    name: null,
    status: 'completed',
    sql: 'SELECT 1',
    source: 'query',
    dataSource: 'inventory',
    progressMessage: null,
    rowCount: 1,
    executionTimeMs: 10,
    errorMessage: null,
    createdAt: '2026-07-19T10:00:00Z',
    startedAt: '2026-07-19T10:00:01Z',
    completedAt: '2026-07-19T10:00:02Z',
    runBy: 'tester',
    canDelete: true,
  };
}

function historyResponse(items: HistoryItem[]): HistoryResponse {
  return { items, total: items.length, offset: 0, limit: 50 };
}

describe('useHistoryData load generations', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('ignores an invalidated response and applies the next load', async () => {
    const loadA = deferred<HistoryResponse>();
    const loadB = deferred<HistoryResponse>();
    vi.mocked(fetchQueryHistory)
      .mockReturnValueOnce(loadA.promise)
      .mockReturnValueOnce(loadB.promise);

    const { result } = renderHook(() => useHistoryData());

    act(() => {
      result.current.invalidateLoads();
    });
    await act(async () => {
      loadA.resolve(historyResponse([historyItem('deleted-job')]));
      await loadA.promise;
    });

    expect(result.current.items).toEqual([]);

    let nextLoad!: Promise<void>;
    act(() => {
      nextLoad = result.current.load();
    });
    await act(async () => {
      loadB.resolve(historyResponse([historyItem('current-job')]));
      await nextLoad;
    });

    expect(result.current.items.map((item) => item.jobId)).toEqual(['current-job']);
    expect(result.current.total).toBe(1);
  });

  it('settles loading when the active request is invalidated without a replacement load', async () => {
    const activeLoad = deferred<HistoryResponse>();
    vi.mocked(fetchQueryHistory).mockReturnValue(activeLoad.promise);

    const { result } = renderHook(() => useHistoryData());

    expect(result.current.loading).toBe(true);

    act(() => {
      result.current.invalidateLoads();
    });

    expect(result.current.loading).toBe(false);

    await act(async () => {
      activeLoad.resolve(historyResponse([historyItem('stale-job')]));
      await activeLoad.promise;
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.items).toEqual([]);
  });

  it('does not let an older request clear newer loading or error state', async () => {
    const loadA = deferred<HistoryResponse>();
    const loadB = deferred<HistoryResponse>();
    vi.mocked(fetchQueryHistory)
      .mockReturnValueOnce(loadA.promise)
      .mockReturnValueOnce(loadB.promise);

    const { result } = renderHook(() => useHistoryData());

    let nextLoad!: Promise<void>;
    act(() => {
      nextLoad = result.current.load();
    });
    await act(async () => {
      loadA.resolve(historyResponse([historyItem('stale-job')]));
      await loadA.promise;
    });

    expect(result.current.loading).toBe(true);
    expect(result.current.items).toEqual([]);

    await act(async () => {
      loadB.reject({ response: { data: { error: 'Newest load failed' } } });
      await nextLoad;
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBe('Newest load failed');
    expect(result.current.items).toEqual([]);
  });

  it('preserves a newer error when an older successful request finishes last', async () => {
    const loadA = deferred<HistoryResponse>();
    const loadB = deferred<HistoryResponse>();
    vi.mocked(fetchQueryHistory)
      .mockReturnValueOnce(loadA.promise)
      .mockReturnValueOnce(loadB.promise);

    const { result } = renderHook(() => useHistoryData());

    let nextLoad!: Promise<void>;
    act(() => {
      nextLoad = result.current.load();
    });
    await act(async () => {
      loadB.reject({ response: { data: { error: 'Newest load failed' } } });
      await nextLoad;
    });

    expect(result.current.error).toBe('Newest load failed');

    await act(async () => {
      loadA.resolve(historyResponse([historyItem('stale-job')]));
      await loadA.promise;
    });

    expect(result.current.error).toBe('Newest load failed');
    expect(result.current.items).toEqual([]);
  });
});
