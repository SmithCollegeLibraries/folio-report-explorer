import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { downloadExportCsv } from '../../api/client';
import { downloadCsv } from '../../utils/csv';
import type { HistoryItem, JobStatusResponse } from '../../types';
import HistoryResultsModal from './HistoryResultsModal';

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client');
  return { ...actual, downloadExportCsv: vi.fn() };
});

vi.mock('../../utils/csv', async () => {
  const actual = await vi.importActual<typeof import('../../utils/csv')>('../../utils/csv');
  return { ...actual, downloadCsv: vi.fn() };
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const item: HistoryItem = {
  jobId: 'synthetic-old-job',
  name: 'Synthetic old report',
  status: 'completed',
  sql: 'SELECT 1',
  source: 'report',
  dataSource: 'folio',
  progressMessage: 'Completed',
  rowCount: 1,
  executionTimeMs: 10,
  errorMessage: null,
  createdAt: '2026-07-19T10:00:00Z',
  startedAt: '2026-07-19T10:00:01Z',
  completedAt: '2026-07-19T10:00:02Z',
  runBy: null,
  canDelete: true,
};

function renderModal(job: JobStatusResponse) {
  return render(
    <HistoryResultsModal
      item={item}
      job={job}
      loading={false}
      onClose={vi.fn()}
      onRename={vi.fn()}
      onSave={vi.fn()}
      suggestions={[]}
      suggestionSource={null}
      suggestionWarnings={[]}
      suggestionsLoading={false}
      suggestionsError={null}
      onGenerateSuggestions={vi.fn()}
      onRunSuggestion={vi.fn()}
      onAskFollowUp={vi.fn()}
    />,
  );
}

describe('HistoryResultsModal unavailable results', () => {
  it('shows an unavailable message when cleanup replaced saved rows with an object', () => {
    const job = {
      jobId: item.jobId,
      status: 'completed',
      sql: item.sql,
      outputMode: 'table',
      columns: ['title'],
      rows: { trimmed: true },
      rowCount: 1,
      executionTimeMs: 10,
    } as unknown as JobStatusResponse;

    renderModal(job);

    expect(screen.getByText(/results are no longer available/i)).toBeInTheDocument();
  });

  it('does not pass non-array retained rows to CSV conversion', async () => {
    const job = {
      jobId: item.jobId,
      status: 'completed',
      sql: item.sql,
      outputMode: 'table',
      columns: ['title'],
      rows: { trimmed: true },
      rowCount: 1,
      executionTimeMs: 10,
    } as unknown as JobStatusResponse;

    renderModal(job);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /download csv/i }));

    expect(downloadCsv).not.toHaveBeenCalled();
    expect(screen.getByText(/results are no longer available/i)).toBeInTheDocument();
  });

  it('shows an unavailable message when a retained export download returns 404', async () => {
    const expired = Promise.reject({ response: { status: 404 } });
    void expired.catch(() => undefined);
    vi.mocked(downloadExportCsv).mockReturnValue(expired);
    const job: JobStatusResponse = {
      jobId: item.jobId,
      status: 'completed',
      sql: item.sql,
      outputMode: 'file',
      downloadUrl: '/query/export/synthetic-old-job',
      progressMessage: 'Completed',
      createdAt: item.createdAt,
      startedAt: item.startedAt,
      completedAt: item.completedAt,
      rowCount: 1,
      executionTimeMs: 10,
    };

    renderModal(job);
    const user = userEvent.setup();
    await user.click(screen.getAllByRole('button', { name: /download csv/i })[0]);

    expect(downloadExportCsv).toHaveBeenCalledWith(item.jobId);
    expect(await screen.findByText(/results are no longer available/i)).toBeInTheDocument();
  });
});
