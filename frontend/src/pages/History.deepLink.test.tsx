import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { checkJobStatus, fetchQueryHistory } from '../api/client';
import { fetchHistoryItem } from '../api/history';
import type { HistoryItem, JobStatusResponse } from '../types';
import History from './History';

vi.mock('../api/history', async () => {
  const actual = await vi.importActual<typeof import('../api/history')>('../api/history');
  return { ...actual, fetchHistoryItem: vi.fn() };
});

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client');
  return { ...actual, checkJobStatus: vi.fn(), fetchQueryHistory: vi.fn() };
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.clearAllMocks();
});

function item(jobId: string, status: HistoryItem['status'], errorMessage: string | null = null): HistoryItem {
  return {
    jobId,
    name: `Synthetic ${status} run`,
    status,
    sql: 'SELECT 1',
    source: 'report',
    dataSource: 'folio',
    progressMessage: status,
    rowCount: 1,
    executionTimeMs: 10,
    errorMessage,
    createdAt: '2026-07-19T10:00:00Z',
    startedAt: null,
    completedAt: status === 'completed' ? '2026-07-19T10:01:00Z' : null,
    runBy: null,
    canDelete: true,
  };
}

function renderHistory(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/history/:jobId?" element={<History />} />
      </Routes>
    </MemoryRouter>,
  );
}

const completedStatus: JobStatusResponse = {
  jobId: 'collapsed-member',
  status: 'completed',
  sql: 'SELECT 1',
  progressMessage: 'Completed',
  createdAt: '2026-07-19T10:00:00Z',
  startedAt: '2026-07-19T10:00:01Z',
  completedAt: '2026-07-19T10:00:02Z',
  columns: ['value'],
  rows: [{ value: 'synthetic' }],
  rowCount: 1,
  executionTimeMs: 10,
};

describe('History deep links', () => {
  it('loads a collapsed group member directly and opens its completed results', async () => {
    const representative = { ...item('representative', 'completed'), groupId: 'group-1', runCount: 2, activeRunCount: 0 } as HistoryItem;
    vi.mocked(fetchQueryHistory).mockResolvedValue({ items: [representative], total: 1, offset: 0, limit: 50 });
    vi.mocked(fetchHistoryItem).mockResolvedValue(item('collapsed-member', 'completed'));
    vi.mocked(checkJobStatus).mockResolvedValue(completedStatus);

    renderHistory('/history/collapsed-member');

    await waitFor(() => expect(fetchHistoryItem).toHaveBeenCalledWith('collapsed-member'));
    expect(await screen.findByText('Original query')).toBeInTheDocument();
    expect(screen.getByText('synthetic')).toBeInTheDocument();
  });

  it('loads a direct link when the current page is empty', async () => {
    vi.mocked(fetchQueryHistory).mockResolvedValue({ items: [], total: 0, offset: 0, limit: 50 });
    vi.mocked(fetchHistoryItem).mockResolvedValue(item('empty-page-job', 'failed', 'Synthetic failure'));

    renderHistory('/history/empty-page-job');

    await waitFor(() => expect(fetchHistoryItem).toHaveBeenCalledWith('empty-page-job'));
    expect(await screen.findByText(/synthetic failure/i)).toBeInTheDocument();
  });

  it.each([
    ['failed', 'Synthetic failure', 'Synthetic failure'],
    ['cancelled', null, /cancelled/i],
    ['running', null, /running/i],
  ] as const)('shows the %s job state from a direct link', async (status, errorMessage, expected) => {
    vi.mocked(fetchQueryHistory).mockResolvedValue({ items: [], total: 0, offset: 0, limit: 50 });
    vi.mocked(fetchHistoryItem).mockResolvedValue(item(`deep-${status}`, status, errorMessage) as HistoryItem);
    vi.mocked(checkJobStatus).mockResolvedValue({
      ...completedStatus,
      jobId: `deep-${status}`,
      status,
      progressMessage: status,
    } as JobStatusResponse);

    renderHistory(`/history/deep-${status}`);

    await waitFor(() => expect(fetchHistoryItem).toHaveBeenCalledWith(`deep-${status}`));
    expect(await screen.findByText(expected)).toBeInTheDocument();
    if (status === 'running') {
      expect(checkJobStatus).toHaveBeenCalledWith('deep-running');
    }
  });

  it('updates an active deep-link job through status polling', async () => {
    vi.useFakeTimers();
    vi.mocked(fetchQueryHistory).mockResolvedValue({ items: [], total: 0, offset: 0, limit: 50 });
    vi.mocked(fetchHistoryItem).mockResolvedValue(item('active-job', 'running'));
    vi.mocked(checkJobStatus)
      .mockResolvedValueOnce({
        ...completedStatus,
        jobId: 'active-job',
        status: 'running',
        progressMessage: 'Running synthetic report',
      })
      .mockResolvedValueOnce({
        ...completedStatus,
        jobId: 'active-job',
        status: 'completed',
        progressMessage: 'Completed synthetic report',
      });

    renderHistory('/history/active-job');
    await act(async () => { await Promise.resolve(); });
    expect(checkJobStatus).toHaveBeenCalledWith('active-job');

    await act(async () => {
      await vi.advanceTimersByTimeAsync(2000);
    });

    expect(checkJobStatus).toHaveBeenCalledTimes(2);
    expect(screen.getByText(/completed synthetic report/i)).toBeInTheDocument();
  });

  it('shows the removed-entry message when a direct link returns 404', async () => {
    vi.mocked(fetchQueryHistory).mockResolvedValue({ items: [], total: 0, offset: 0, limit: 50 });
    vi.mocked(fetchHistoryItem).mockRejectedValue({ response: { status: 404 } });

    renderHistory('/history/gone-job');

    expect(await screen.findByText(/this history entry no longer exists/i)).toBeInTheDocument();
  });
});
