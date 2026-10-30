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

function groupResponse(items: HistoryItem[] = []): HistoryGroupResponse {
  return { items, total: items.length, limit: 20, offset: 0 };
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
});
