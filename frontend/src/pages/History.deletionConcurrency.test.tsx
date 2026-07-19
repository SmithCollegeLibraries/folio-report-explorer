import { act, cleanup, render, screen, within } from '@testing-library/react';
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

    await act(async () => {
      secondDeletion.resolve();
      await secondDeletion.promise;
    });
    expect(screen.getByText('First query')).toBeInTheDocument();
    expect(screen.queryByText('Second query')).not.toBeInTheDocument();
    expect(screen.getByText('1 query')).toBeInTheDocument();

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

    await act(async () => {
      deletion.resolve();
      await deletion.promise;
    });

    expect(screen.queryByTitle('Close (Esc)')).not.toBeInTheDocument();
    expect(screen.getByText('0 queries')).toBeInTheDocument();
  });
});
