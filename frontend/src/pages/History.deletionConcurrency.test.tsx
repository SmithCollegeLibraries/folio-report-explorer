import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { checkJobStatus, deleteHistoryJob, fetchQueryHistory } from '../api/client';
import type { HistoryItem } from '../types';
import History from './History';

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client');

  return {
    ...actual,
    checkJobStatus: vi.fn(),
    deleteHistoryJob: vi.fn(),
    fetchQueryHistory: vi.fn(),
  };
});

function deferred() {
  let resolve!: () => void;
  const promise = new Promise<void>((resolvePromise) => {
    resolve = resolvePromise;
  });
  return { promise, resolve };
}

function deferredValue<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((resolvePromise) => {
    resolve = resolvePromise;
  });
  return { promise, resolve };
}

function historyItem(jobId: string, name: string): HistoryItem {
  return {
    jobId,
    name,
    status: 'completed',
    sql: 'SELECT 1;',
    source: 'nl',
    dataSource: 'folio',
    progressMessage: null,
    rowCount: 1,
    executionTimeMs: 10,
    errorMessage: null,
    createdAt: '2026-07-19T12:00:00Z',
    startedAt: '2026-07-19T12:00:01Z',
    completedAt: '2026-07-19T12:00:02Z',
    runBy: 'tester',
    canDelete: true,
  };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('History deletion concurrency', () => {
  it('keeps both rows deleted when concurrent single deletions resolve out of order', async () => {
    const firstDeletion = deferred();
    const secondDeletion = deferred();
    vi.mocked(fetchQueryHistory).mockResolvedValue({
      items: [historyItem('first-job', 'First query'), historyItem('second-job', 'Second query')],
      total: 2,
      offset: 0,
      limit: 50,
    });
    vi.mocked(deleteHistoryJob).mockImplementation((jobId) => (
      jobId === 'first-job' ? firstDeletion.promise : secondDeletion.promise
    ));

    render(
      <MemoryRouter initialEntries={['/history']}>
        <Routes>
          <Route path="/history/*" element={<History />} />
        </Routes>
      </MemoryRouter>,
    );

    const user = userEvent.setup();
    const firstRow = (await screen.findByText('First query')).closest('tr');
    const secondRow = screen.getByText('Second query').closest('tr');
    expect(firstRow).not.toBeNull();
    expect(secondRow).not.toBeNull();

    await user.click(within(firstRow!).getByTitle('Delete from history'));
    await user.click(within(firstRow!).getByRole('button', { name: 'Yes' }));
    await user.click(within(secondRow!).getByTitle('Delete from history'));
    await user.click(within(secondRow!).getByRole('button', { name: 'Yes' }));

    vi.mocked(fetchQueryHistory).mockResolvedValue({
      items: [historyItem('first-job', 'First query')],
      total: 1,
      offset: 0,
      limit: 50,
    });
    await act(async () => {
      secondDeletion.resolve();
      await secondDeletion.promise;
    });
    expect(screen.getByText('First query')).toBeInTheDocument();
    expect(screen.queryByText('Second query')).not.toBeInTheDocument();
    expect(screen.getByText('1 query')).toBeInTheDocument();

    vi.mocked(fetchQueryHistory).mockResolvedValue({
      items: [],
      total: 0,
      offset: 0,
      limit: 50,
    });
    await act(async () => {
      firstDeletion.resolve();
      await firstDeletion.promise;
    });
    expect(screen.queryByText('First query')).not.toBeInTheDocument();
    expect(screen.queryByText('Second query')).not.toBeInTheDocument();
    expect(screen.getByText('0 queries')).toBeInTheDocument();
  });

  it('closes a modal opened for a row while its deletion is pending', async () => {
    const deletion = deferred();
    vi.mocked(fetchQueryHistory).mockResolvedValue({
      items: [historyItem('first-job', 'First query')],
      total: 1,
      offset: 0,
      limit: 50,
    });
    vi.mocked(deleteHistoryJob).mockReturnValue(deletion.promise);
    vi.mocked(checkJobStatus).mockResolvedValue({
      jobId: 'first-job',
      status: 'completed',
      sql: 'SELECT 1;',
      progressMessage: 'Completed',
      createdAt: '2026-07-19T12:00:00Z',
      startedAt: '2026-07-19T12:00:01Z',
      completedAt: '2026-07-19T12:00:02Z',
    });

    render(
      <MemoryRouter initialEntries={['/history']}>
        <Routes>
          <Route path="/history/*" element={<History />} />
        </Routes>
      </MemoryRouter>,
    );

    const user = userEvent.setup();
    const row = (await screen.findByText('First query')).closest('tr');
    expect(row).not.toBeNull();

    await user.click(within(row!).getByTitle('Delete from history'));
    await user.click(within(row!).getByRole('button', { name: 'Yes' }));
    await user.click(screen.getByText('First query'));
    expect(await screen.findByTitle('Close (Esc)')).toBeInTheDocument();

    vi.mocked(fetchQueryHistory).mockResolvedValue({
      items: [],
      total: 0,
      offset: 0,
      limit: 50,
    });
    await act(async () => {
      deletion.resolve();
      await deletion.promise;
    });

    expect(screen.queryByTitle('Close (Esc)')).not.toBeInTheDocument();
    expect(screen.getByText('0 queries')).toBeInTheDocument();
  });

  it('reloads the latest My Queries cohort when deletion finishes during the transition', async () => {
    const deletion = deferred();
    const transitioningLoad = deferredValue<{
      items: HistoryItem[];
      total: number;
      offset: number;
      limit: number;
    }>();
    const reconciliationLoad = deferredValue<{
      items: HistoryItem[];
      total: number;
      offset: number;
      limit: number;
    }>();
    const currentItem = historyItem('mine-job', 'My current query');
    vi.mocked(fetchQueryHistory)
      .mockResolvedValueOnce({
        items: [historyItem('deleted-job', 'Delete me'), historyItem('shared-job', 'Shared query')],
        total: 2,
        offset: 0,
        limit: 50,
      })
      .mockReturnValueOnce(transitioningLoad.promise)
      .mockReturnValueOnce(reconciliationLoad.promise);
    vi.mocked(deleteHistoryJob).mockReturnValue(deletion.promise);

    render(
      <MemoryRouter initialEntries={['/history']}>
        <Routes>
          <Route path="/history/*" element={<History />} />
        </Routes>
      </MemoryRouter>,
    );

    const user = userEvent.setup();
    const row = (await screen.findByText('Delete me')).closest('tr');
    expect(row).not.toBeNull();

    await user.click(within(row!).getByTitle('Delete from history'));
    await user.click(within(row!).getByRole('button', { name: 'Yes' }));
    await user.click(screen.getByRole('button', { name: 'My Queries' }));
    await waitFor(() => expect(fetchQueryHistory).toHaveBeenCalledTimes(2));

    await act(async () => {
      deletion.resolve();
      await deletion.promise;
    });

    await waitFor(() => expect(fetchQueryHistory).toHaveBeenCalledTimes(3));
    expect(fetchQueryHistory).toHaveBeenLastCalledWith(50, 0, 'all', true);

    await act(async () => {
      transitioningLoad.resolve({
        items: [historyItem('stale-job', 'Stale cohort')],
        total: 99,
        offset: 0,
        limit: 50,
      });
      reconciliationLoad.resolve({ items: [currentItem], total: 1, offset: 0, limit: 50 });
      await Promise.all([transitioningLoad.promise, reconciliationLoad.promise]);
    });

    expect(screen.getByText('My current query')).toBeInTheDocument();
    expect(screen.queryByText('Stale cohort')).not.toBeInTheDocument();
    expect(screen.getByText('1 query')).toBeInTheDocument();
    expect(screen.queryByText('Loading history…')).not.toBeInTheDocument();
  });
});
