import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchHistoryGroup } from '../api/history';
import type { HistoryGroupListItem, HistoryGroupResponse } from '../api/history';
import type { HistoryItem } from '../types';
import { useHistoryGroup } from './useHistoryGroup';

vi.mock('../api/history', () => ({ fetchHistoryGroup: vi.fn() }));

afterEach(() => vi.clearAllMocks());

function group(overrides: Partial<HistoryGroupListItem> = {}): HistoryGroupListItem {
  const base: HistoryItem = {
    jobId: 'representative',
    name: 'Synthetic report',
    status: 'completed',
    sql: 'SELECT 1',
    source: 'report',
    dataSource: 'local',
    progressMessage: null,
    rowCount: 3,
    executionTimeMs: 10,
    errorMessage: null,
    createdAt: '2026-07-19T10:00:00Z',
    startedAt: null,
    completedAt: '2026-07-19T10:01:00Z',
    runBy: 'synthetic@example.test',
    canDelete: true,
  };
  return { ...base, groupId: 'stable-group-id', runCount: 2, activeRunCount: 1, ...overrides };
}

function groupResponse(items: HistoryItem[] = [], offset = 0, total = items.length): HistoryGroupResponse {
  return { items, total, limit: 20, offset };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((resolvePromise) => { resolve = resolvePromise; });
  return { promise, resolve };
}

describe('useHistoryGroup expansion state', () => {
  it('keeps an open expansion through a background poll and refetches its members', async () => {
    vi.mocked(fetchHistoryGroup)
      .mockResolvedValueOnce(groupResponse())
      .mockResolvedValueOnce(groupResponse());
    const { result, rerender } = renderHook(
      ({ items }) => useHistoryGroup(items, 'all', 0),
      { initialProps: { items: [group()] } },
    );

    await act(async () => result.current.toggleGroup(group()));
    expect(result.current.expandedGroupIds.has('stable-group-id')).toBe(true);

    rerender({ items: [group({ activeRunCount: 0 })] });
    await waitFor(() => expect(fetchHistoryGroup).toHaveBeenCalledTimes(2));
    expect(result.current.expandedGroupIds.has('stable-group-id')).toBe(true);
  });

  it('refetches open members when the last active run completes and after cancellation', async () => {
    vi.mocked(fetchHistoryGroup)
      .mockResolvedValueOnce(groupResponse())
      .mockResolvedValueOnce(groupResponse())
      .mockResolvedValueOnce(groupResponse());
    const { result, rerender } = renderHook(
      ({ items }) => useHistoryGroup(items, 'all', 0),
      { initialProps: { items: [group()] } },
    );

    await act(async () => result.current.toggleGroup(group()));
    rerender({ items: [group({ activeRunCount: 0 })] });
    await waitFor(() => expect(fetchHistoryGroup).toHaveBeenCalledTimes(2));
    await act(async () => result.current.refreshOpenGroups());

    expect(fetchHistoryGroup).toHaveBeenCalledTimes(3);
    expect(fetchHistoryGroup).toHaveBeenLastCalledWith('representative', {
      status: 'all', limit: 20, offset: 0,
    });
  });

  it('collapses open groups when the status filter or page changes', async () => {
    vi.mocked(fetchHistoryGroup).mockResolvedValue(groupResponse());
    const { result, rerender } = renderHook(
      ({ status, offset }) => useHistoryGroup([group()], status, offset),
      { initialProps: { status: 'all', offset: 0 } },
    );
    await act(async () => result.current.toggleGroup(group()));
    rerender({ status: 'completed', offset: 0 });
    expect(result.current.expandedGroupIds.size).toBe(0);

    await act(async () => result.current.toggleGroup(group()));
    rerender({ status: 'completed', offset: 50 });
    expect(result.current.expandedGroupIds.size).toBe(0);
  });

  it('drops a delayed first-page poll after a newer next-runs request', async () => {
    const firstPage = deferred<HistoryGroupResponse>();
    const nextPage = deferred<HistoryGroupResponse>();
    vi.mocked(fetchHistoryGroup).mockReturnValueOnce(firstPage.promise).mockReturnValueOnce(nextPage.promise);
    const { result } = renderHook(() => useHistoryGroup([group()], 'all', 0));
    let firstRequest!: Promise<void>;
    let nextRequest!: Promise<void>;

    act(() => { firstRequest = result.current.toggleGroup(group()); });
    act(() => { nextRequest = result.current.setGroupOffset(group(), 20); });

    await act(async () => {
      nextPage.resolve(groupResponse([{
        ...group(), jobId: 'next-runs-page', name: 'Later run page',
      }], 20, 45));
      await nextRequest;
    });
    await act(async () => {
      firstPage.resolve(groupResponse([{
        ...group(), jobId: 'first-runs-page', name: 'Stale first run page',
      }], 0, 45));
      await firstRequest;
    });

    expect(result.current.membersByGroup['stable-group-id'].offset).toBe(20);
    expect(result.current.membersByGroup['stable-group-id'].items[0].jobId).toBe('next-runs-page');
  });

  it('refreshes the requested next-runs page while navigation is still pending', async () => {
    const nextPage = deferred<HistoryGroupResponse>();
    vi.mocked(fetchHistoryGroup)
      .mockResolvedValueOnce(groupResponse([{
        ...group(), jobId: 'first-runs-page', name: 'First runs page',
      }], 0, 45))
      .mockReturnValueOnce(nextPage.promise)
      .mockImplementationOnce(async (_jobId, parameters) => groupResponse([{
        ...group(),
        jobId: parameters.offset === 20 ? 'refreshed-next-page' : 'refreshed-first-page',
        name: parameters.offset === 20 ? 'Refreshed next runs page' : 'Refreshed first runs page',
      }], parameters.offset ?? 0, 45));
    const { result } = renderHook(() => useHistoryGroup([group()], 'all', 0));

    await act(async () => result.current.toggleGroup(group()));
    let navigation!: Promise<void>;
    act(() => { navigation = result.current.setGroupOffset(group(), 20); });
    expect(fetchHistoryGroup).toHaveBeenLastCalledWith('representative', {
      status: 'all', limit: 20, offset: 20,
    });

    await act(async () => result.current.refreshOpenGroups());
    expect(fetchHistoryGroup).toHaveBeenLastCalledWith('representative', {
      status: 'all', limit: 20, offset: 20,
    });

    await act(async () => {
      nextPage.resolve(groupResponse([{
        ...group(), jobId: 'delayed-next-page', name: 'Delayed next runs page',
      }], 20, 45));
      await navigation;
    });

    expect(result.current.membersByGroup['stable-group-id'].offset).toBe(20);
    expect(result.current.membersByGroup['stable-group-id'].items[0].jobId).toBe('refreshed-next-page');
  });

  it('drops an old filter response after the group is reopened in the new filter', async () => {
    const oldFilter = deferred<HistoryGroupResponse>();
    const newFilter = deferred<HistoryGroupResponse>();
    vi.mocked(fetchHistoryGroup).mockReturnValueOnce(oldFilter.promise).mockReturnValueOnce(newFilter.promise);
    const { result, rerender } = renderHook(
      ({ status }) => useHistoryGroup([group()], status, 0),
      { initialProps: { status: 'all' } },
    );
    let oldRequest!: Promise<void>;
    let newRequest!: Promise<void>;

    act(() => { oldRequest = result.current.toggleGroup(group()); });
    rerender({ status: 'failed' });
    expect(result.current.expandedGroupIds.size).toBe(0);
    act(() => { newRequest = result.current.toggleGroup(group()); });

    await act(async () => {
      newFilter.resolve(groupResponse([{
        ...group(), jobId: 'failed-member', status: 'failed', name: 'Failed-filter run',
      }]));
      await newRequest;
    });
    await act(async () => {
      oldFilter.resolve(groupResponse([{
        ...group(), jobId: 'all-member', name: 'Stale all-filter run',
      }]));
      await oldRequest;
    });

    expect(result.current.membersByGroup['stable-group-id'].items[0].jobId).toBe('failed-member');
    expect(result.current.expandedGroupIds.has('stable-group-id')).toBe(true);
  });
});
