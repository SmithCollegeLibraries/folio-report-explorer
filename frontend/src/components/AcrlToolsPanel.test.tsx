import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlToolsPanel from './AcrlToolsPanel';
import type { AcrlSurveyYear } from '../types/acrl';

const { getThreshold, saveThreshold, previewImport, confirmImport } = vi.hoisted(() => ({
  getThreshold: vi.fn(),
  saveThreshold: vi.fn(),
  previewImport: vi.fn(),
  confirmImport: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlThreshold: getThreshold,
  saveAcrlThreshold: saveThreshold,
  previewAcrlImport: previewImport,
  confirmAcrlImport: confirmImport,
}));

afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});

const years: AcrlSurveyYear[] = [{
  surveyYear: 2025, status: 'published', sourceFileName: null, surveyPromptCount: 69, trendsPromptCount: 15,
  surveyItemCount: 79, trendsItemCount: 15, totalItemCount: 94, publishedAt: '2026-01-01',
}];

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AcrlToolsPanel years={years} /></QueryClientProvider>);
}

describe('ACRL administrator tools', () => {
  it('previews CSV rows and confirms a clean import', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    previewImport.mockResolvedValue({
      surveyYear: 2025, sourceLabel: 'FY2025 source', overwrite: true,
      matchedCount: 1, unknownCount: 0, typeErrorCount: 0, existingChanges: 1,
      items: [{ line: 2, sheet: 'survey', questionId: '60', part: 'A', status: 'will_replace', currentValue: '100', value: '18527', error: null }],
    });
    confirmImport.mockResolvedValue({ preview: { matchedCount: 1 }, result: { imported: 1, unchanged: 0, skipped: 0 } });
    renderPanel();
    const file = new File(['sheet,question_id,part,value,note\nsurvey,60,A,18527,'], 'values.csv', { type: 'text/csv' });
    Object.defineProperty(file, 'text', { value: async () => 'sheet,question_id,part,value,note\nsurvey,60,A,18527,' });
    await userEvent.upload(screen.getByLabelText('CSV file'), file);
    await screen.findByText('1 CSV answer rows loaded.');
    await userEvent.type(screen.getByLabelText('Source label'), 'FY2025 source');
    await userEvent.click(screen.getByLabelText('Overwrite existing non-empty values'));
    await userEvent.click(screen.getByRole('button', { name: 'Preview import' }));
    expect(await screen.findByText(/Matched 1 · unknown IDs 0 · type errors 0/)).toBeInTheDocument();
    expect(screen.getByText(/current 100 → proposed 18527/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Confirm import' }));
    await waitFor(() => expect(confirmImport).toHaveBeenCalledWith(2025, expect.objectContaining({ sourceLabel: 'FY2025 source', overwrite: true })));
    expect(await screen.findByRole('status')).toHaveTextContent('Imported 1; unchanged 0; skipped 0.');
  });

  it('shows reading, checking, and importing states and keeps the result after completion', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    let finishRead!: (contents: string) => void;
    let finishPreview!: (value: unknown) => void;
    let finishImport!: (value: unknown) => void;
    const previewPromise = new Promise((resolve) => { finishPreview = resolve; });
    const importPromise = new Promise((resolve) => { finishImport = resolve; });
    previewImport.mockReturnValue(previewPromise);
    confirmImport.mockReturnValue(importPromise);
    const user = userEvent.setup();
    renderPanel();

    const file = new File(['pending'], 'values.csv', { type: 'text/csv' });
    Object.defineProperty(file, 'text', { value: () => new Promise<string>((resolve) => { finishRead = resolve; }) });
    const fileInput = screen.getByLabelText('CSV file');
    await user.upload(fileInput, file);

    expect(await screen.findByRole('status')).toHaveTextContent('Reading file…');
    expect(screen.getByRole('region', { name: 'Import previous-year values' })).toHaveAttribute('aria-busy', 'true');
    expect(fileInput).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Preview import' })).toBeDisabled();
    expect(fileInput).toHaveValue('');

    finishRead('sheet,question_id,part,value,note\nsurvey,60,A,18527,');
    await screen.findByText('1 CSV answer rows loaded.');
    await user.type(screen.getByLabelText('Source label'), 'FY2025 source');
    await user.click(screen.getByRole('button', { name: 'Preview import' }));
    expect(screen.getByRole('status')).toHaveTextContent('Checking 1 row…');
    expect(screen.getByRole('button', { name: 'Preview import' })).toBeDisabled();
    finishPreview({
      surveyYear: 2025, sourceLabel: 'FY2025 source', overwrite: false,
      matchedCount: 1, unknownCount: 0, typeErrorCount: 0, existingChanges: 0, items: [],
    });
    expect(await screen.findByText('Preview ready: 1 row checked.')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Confirm import' }));
    expect(screen.getByRole('status')).toHaveTextContent('Importing…');
    expect(screen.getByRole('button', { name: 'Confirm import' })).toBeDisabled();
    finishImport({ preview: {}, result: { imported: 1, unchanged: 0, skipped: 0 } });
    expect(await screen.findByText('Imported 1; unchanged 0; skipped 0.')).toBeInTheDocument();
    expect(fileInput).toBeEnabled();
    expect(screen.getByRole('region', { name: 'Import previous-year values' })).toHaveAttribute('aria-busy', 'false');
  });

  it('announces a file read failure and permits selecting the same file again', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    const user = userEvent.setup();
    renderPanel();
    const file = new File(['bad'], 'values.csv', { type: 'text/csv' });
    const read = vi.fn(async () => { throw new Error('Synthetic read failure.'); });
    Object.defineProperty(file, 'text', { value: read });
    const input = screen.getByLabelText('CSV file');

    await user.upload(input, file);

    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic read failure.');
    await waitFor(() => expect(read).toHaveBeenCalledTimes(1));
    expect(input).toHaveValue('');
    await user.upload(input, file);
    expect(screen.getAllByRole('alert')[0]).toHaveTextContent('Synthetic read failure.');
    expect(read).toHaveBeenCalledTimes(2);
  });
});
