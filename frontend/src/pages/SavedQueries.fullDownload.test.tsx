import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { checkJobStatus, downloadExportCsv, listSaved, refreshDashboardCard } from '../api/client';
import SavedQueries from './SavedQueries';

vi.mock('../api/client', () => ({
  checkJobStatus: vi.fn(),
  deleteSaved: vi.fn(),
  downloadExportCsv: vi.fn(),
  listSaved: vi.fn(),
  promoteToReport: vi.fn(),
  refreshDashboardCard: vi.fn(),
  togglePin: vi.fn(),
}));

const savedQuery = {
  id: 4,
  name: 'Saved export',
  description: null,
  generated_sql: 'SELECT instance_uuid FROM instance',
  source: 'builder' as const,
  is_pinned: false,
  is_global: false,
  last_job_id: 'job-42',
  created_at: '2026-10-23T12:00:00Z',
  updated_at: '2026-10-23T12:00:00Z',
};

const completedFileJob = {
  jobId: 'job-42',
  status: 'completed' as const,
  outputMode: 'file' as const,
  sql: savedQuery.generated_sql,
  progressMessage: 'Completed',
  createdAt: null,
  startedAt: null,
  completedAt: null,
  columns: ['UUID'],
  rows: [{ UUID: 'instance-1' }],
  rowCount: 241,
  executionTimeMs: 18,
  downloadUrl: '/query/export/job-42',
};

function renderSavedQueries() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <SavedQueries />
    </QueryClientProvider>,
  );
}

describe('SavedQueries full CSV download', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(listSaved).mockResolvedValue([savedQuery]);
    vi.mocked(checkJobStatus).mockResolvedValue(completedFileJob);
    vi.mocked(downloadExportCsv).mockResolvedValue(undefined);
  });
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  it('keeps the job ID when loading a saved file export', async () => {
    renderSavedQueries();

    fireEvent.click((await screen.findAllByRole('button'))[0]);
    fireEvent.click(await screen.findByRole('button', { name: 'Download Full CSV' }));

    expect(downloadExportCsv).toHaveBeenCalledWith('job-42');
  });

  it('keeps the job ID when a saved query finishes rerunning as a file export', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    vi.mocked(refreshDashboardCard).mockResolvedValue({ jobId: 'job-42' });
    renderSavedQueries();

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Download Full CSV' }));

    expect(downloadExportCsv).toHaveBeenCalledWith('job-42');
  });
});
