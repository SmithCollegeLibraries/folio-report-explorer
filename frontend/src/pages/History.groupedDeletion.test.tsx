import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { deleteHistoryJob, fetchQueryHistory } from '../api/client';
import { fetchHistoryGroup } from '../api/history';
import type { HistoryGroupListItem } from '../api/history';
import type { HistoryItem } from '../types';
import History from './History';

vi.mock('../api/history', async () => {
  const actual = await vi.importActual<typeof import('../api/history')>('../api/history');
  return { ...actual, fetchHistoryGroup: vi.fn() };
});

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client');
  return { ...actual, deleteHistoryJob: vi.fn(), fetchQueryHistory: vi.fn() };
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function historyItem(jobId: string, name: string): HistoryItem {
  return {
    jobId,
    name,
    status: 'completed',
    sql: 'SELECT 1',
    source: 'report',
    dataSource: 'folio',
    progressMessage: null,
    rowCount: 1,
    executionTimeMs: 10,
    errorMessage: null,
    createdAt: '2026-07-19T10:00:00Z',
    startedAt: null,
    completedAt: '2026-07-19T10:01:00Z',
    runBy: null,
    canDelete: true,
  };
}

function groupedItem(jobId: string, name: string, runCount: number, groupId = 'stable-group-id'): HistoryGroupListItem {
  return {
    ...historyItem(jobId, name),
    groupId,
    runCount,
    activeRunCount: 0,
  };
}

describe('History grouped deletion', () => {
  it('shows the next newest member after deleting a representative with members left', async () => {
    vi.mocked(fetchQueryHistory)
      .mockResolvedValueOnce({
        items: [groupedItem('newest-member', 'Newest synthetic run', 2)],
        total: 1,
        offset: 0,
        limit: 50,
      })
      .mockResolvedValueOnce({
        items: [groupedItem('next-member', 'Next newest synthetic run', 1)],
        total: 1,
        offset: 0,
        limit: 50,
      });
    vi.mocked(fetchHistoryGroup).mockResolvedValue({
      items: [historyItem('newest-member', 'Newest synthetic run'), historyItem('next-member', 'Next newest synthetic run')],
      total: 2,
      offset: 0,
      limit: 20,
    });
    vi.mocked(deleteHistoryJob).mockResolvedValue(undefined);

    render(
      <MemoryRouter initialEntries={['/history']}>
        <Routes>
          <Route path="/history/:jobId?" element={<History />} />
        </Routes>
      </MemoryRouter>,
    );

    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /run 2 times/i }));
    const newestRow = screen.getByText('Newest synthetic run').closest('tr');
    expect(newestRow).not.toBeNull();
    await user.click(within(newestRow!).getByTitle('Delete from history'));
    await user.click(within(newestRow!).getByRole('button', { name: 'Yes' }));

    expect(await screen.findByText('Next newest synthetic run')).toBeInTheDocument();
    await waitFor(() => expect(fetchQueryHistory).toHaveBeenCalledTimes(2));
    expect(screen.queryByText('Newest synthetic run')).not.toBeInTheDocument();
  });

  it('keeps a late-page expansion open on the next representative after deletion', async () => {
    const firstPage = Array.from({ length: 50 }, (_, index) => (
      groupedItem(`page-one-${index}`, `Page one group ${index}`, 1, `page-one-group-${index}`)
    ));
    const oldRepresentative = groupedItem('late-representative', 'Late page representative', 3, 'late-page-group');
    const nextRepresentative = groupedItem('late-next', 'Late page next newest', 2, 'late-page-group');
    const olderMember = historyItem('late-older', 'Late page older member');
    let representativeDeleted = false;

    vi.mocked(fetchQueryHistory).mockImplementation(async (limit = 50, requestedOffset = 0) => ({
      items: requestedOffset === 50
        ? [representativeDeleted ? nextRepresentative : oldRepresentative]
        : firstPage,
      total: 51,
      offset: requestedOffset,
      limit,
    }));
    vi.mocked(fetchHistoryGroup).mockImplementation(async (_jobId, parameters) => ({
      items: representativeDeleted
        ? [historyItem('late-next', 'Late page next newest'), olderMember]
        : [historyItem('late-representative', 'Late page representative'), historyItem('late-next', 'Late page next newest'), olderMember],
      total: representativeDeleted ? 2 : 3,
      offset: parameters.offset ?? 0,
      limit: parameters.limit ?? 20,
    }));
    vi.mocked(deleteHistoryJob).mockImplementation(async () => {
      representativeDeleted = true;
    });

    render(
      <MemoryRouter initialEntries={['/history']}>
        <Routes>
          <Route path="/history/:jobId?" element={<History />} />
        </Routes>
      </MemoryRouter>,
    );

    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /next/i }));
    const representativeRow = (await screen.findByText('Late page representative')).closest('tr');
    expect(representativeRow).not.toBeNull();
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /run 3 times/i }));
    expect(await screen.findByText('Late page next newest')).toBeInTheDocument();
    await user.click(within(representativeRow!).getByTitle('Delete from history'));
    await user.click(within(representativeRow!).getByRole('button', { name: 'Yes' }));

    await waitFor(() => expect(screen.getByText('Page 2 of 2')).toBeInTheDocument());
    const nextRow = await screen.findByText('Late page next newest');
    await waitFor(() => expect(within(nextRow.closest('tr')!).getByTitle('Delete from history')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /run 2 times/i })).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByText('Late page older member')).toBeInTheDocument();
  });
});
