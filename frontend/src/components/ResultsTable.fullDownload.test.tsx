import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ExecuteResponse } from '../types';
import { downloadExportCsv } from '../api/client';
import ResultsTable from './ResultsTable';

vi.mock('../api/client', () => ({
  downloadExportCsv: vi.fn(),
}));

const fileResult = {
  columns: ['UUID'],
  rows: [{ UUID: 'instance-1' }],
  rowCount: 241,
  executionTimeMs: 18,
  sql: 'select instance_uuid',
  outputMode: 'file',
  downloadUrl: '/query/export/job-8',
  jobId: 'job-8',
} as ExecuteResponse;

describe('ResultsTable full CSV download', () => {
  beforeEach(() => vi.clearAllMocks());
  afterEach(cleanup);

  it('calls the authenticated full export download with the job ID', async () => {
    vi.mocked(downloadExportCsv).mockResolvedValue(undefined);
    const onFullDownload = vi.fn();
    render(<ResultsTable data={fileResult} onFullDownload={onFullDownload} />);

    fireEvent.click(screen.getByRole('button', { name: 'Download Full CSV' }));

    await waitFor(() => expect(downloadExportCsv).toHaveBeenCalledWith('job-8'));
    expect(onFullDownload).toHaveBeenCalledOnce();
    expect(screen.queryByRole('link', { name: 'Download Full CSV' })).not.toBeInTheDocument();
  });

  it('shows a preparing state while the full export is downloading', async () => {
    let finishDownload: () => void = () => undefined;
    vi.mocked(downloadExportCsv).mockImplementation(
      () => new Promise((resolve) => { finishDownload = resolve; }),
    );
    render(<ResultsTable data={fileResult} />);

    fireEvent.click(screen.getByRole('button', { name: 'Download Full CSV' }));

    expect(screen.getByRole('button', { name: 'Preparing download…' })).toBeDisabled();
    await act(async () => finishDownload());
  });

  it('shows an error when the full export download fails', async () => {
    vi.mocked(downloadExportCsv).mockRejectedValueOnce(new Error('Not Found'));
    render(<ResultsTable data={fileResult} />);

    fireEvent.click(screen.getByRole('button', { name: 'Download Full CSV' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Unable to download full CSV.');
  });

  it('keeps the full-download hint when a file export has no inline preview', () => {
    render(<ResultsTable data={{ ...fileResult, columns: [], rows: [] }} />);

    expect(screen.getByRole('button', { name: 'Download Full CSV' })).toBeInTheDocument();
    expect(screen.getByText(/Use Download Full CSV to retrieve the export file\./)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'CSV (preview, 0 rows)' })).toBeDisabled();
  });

  it('labels the inline CSV action with the number of preview rows', () => {
    render(<ResultsTable data={fileResult} />);

    expect(screen.getByRole('button', { name: 'CSV (preview, 1 rows)' })).toBeInTheDocument();
  });
});
