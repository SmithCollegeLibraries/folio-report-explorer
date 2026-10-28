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
}, {
  surveyYear: 2026, status: 'published', sourceFileName: null, surveyPromptCount: 69, trendsPromptCount: 15,
  surveyItemCount: 79, trendsItemCount: 15, totalItemCount: 94, publishedAt: '2026-01-01',
}];

function makeCsvFile(name: string) {
  const contents = 'sheet,question_id,part,value,note\nsurvey,60,A,18527,';
  const file = new File([contents], name, { type: 'text/csv' });
  Object.defineProperty(file, 'text', { value: async () => contents });
  return file;
}

function renderPanel(panelYears: AcrlSurveyYear[] = years) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AcrlToolsPanel years={panelYears} /></QueryClientProvider>);
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
    await userEvent.selectOptions(screen.getByLabelText('Published survey year'), '2025');
    const file = makeCsvFile('values.csv');
    await userEvent.upload(screen.getByLabelText('CSV file'), file);
    await screen.findByText('1 CSV answer rows loaded.');
    await userEvent.type(screen.getByLabelText('Source label (required)'), 'FY2025 source');
    await userEvent.click(screen.getByLabelText('Overwrite existing non-empty values'));
    await userEvent.click(screen.getByRole('button', { name: 'Preview import' }));
    expect(await screen.findByText(/Matched 1 · unknown IDs 0 · type errors 0/)).toBeInTheDocument();
    expect(screen.getByText(/current 100 → proposed 18527/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Confirm import' }));
    await waitFor(() => expect(confirmImport).toHaveBeenCalledWith(2025, expect.objectContaining({ sourceLabel: 'FY2025 source', overwrite: true })));
    expect(await screen.findByRole('status')).toHaveTextContent('Imported 1; unchanged 0; skipped 0.');
    expect(screen.getByLabelText('Published survey year')).toHaveValue('');
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
    await user.selectOptions(screen.getByLabelText('Published survey year'), '2025');

    const file = new File(['pending'], 'values.csv', { type: 'text/csv' });
    Object.defineProperty(file, 'text', { value: () => new Promise<string>((resolve) => { finishRead = resolve; }) });
    const fileInput = screen.getByLabelText('CSV file');
    await user.upload(fileInput, file);

    expect(await screen.findByRole('status')).toHaveTextContent('Reading file…');
    expect(screen.getByRole('region', { name: 'Import previous-year values' })).toHaveAttribute('aria-busy', 'true');
    expect(fileInput).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Preview import' })).toBeDisabled();
    expect(screen.queryByText('Add a source label to preview this import.')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Preview import' })).not.toHaveAttribute('aria-describedby');
    expect(fileInput).toHaveValue('');

    finishRead('sheet,question_id,part,value,note\nsurvey,60,A,18527,');
    await screen.findByText('1 CSV answer rows loaded.');
    await user.type(screen.getByLabelText('Source label (required)'), 'FY2025 source');
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
    expect(screen.getByLabelText('Published survey year')).toHaveValue('');
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

  it('shows the first missing preview requirement and hides the hint when the import is ready', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    const user = userEvent.setup();
    renderPanel();
    const previewButton = screen.getByRole('button', { name: 'Preview import' });

    expect(screen.getByText('Choose a published survey year.')).toBeInTheDocument();
    expect(previewButton).toHaveAttribute('aria-describedby', 'acrl-import-preview-hint');

    await user.selectOptions(screen.getByLabelText('Published survey year'), '2026');
    expect(screen.getByText('Add a source label to preview this import.')).toBeInTheDocument();
    expect(screen.queryByText('Choose a CSV file.')).not.toBeInTheDocument();
    await user.type(screen.getByLabelText('Source label (required)'), 'FY2025 Benchmark submission');
    expect(screen.getByText('Choose a CSV file.')).toBeInTheDocument();

    await user.upload(screen.getByLabelText('CSV file'), makeCsvFile('values.csv'));

    expect(await screen.findByText('1 CSV answer rows loaded.')).toBeInTheDocument();
    expect(screen.queryByText('Choose a CSV file.')).not.toBeInTheDocument();
    expect(previewButton).toBeEnabled();
    expect(previewButton).not.toHaveAttribute('aria-describedby');
  });

  it('explains when no published survey years are available', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    renderPanel([]);

    expect(await screen.findByText("No published survey years yet. Publish a year's catalogue first.")).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Preview import' })).toHaveAttribute('aria-describedby', 'acrl-import-preview-hint');
  });

  it('marks the source label required and keeps whitespace-only labels invalid', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    const user = userEvent.setup();
    renderPanel();
    await user.selectOptions(screen.getByLabelText('Published survey year'), '2025');
    const sourceLabel = screen.getByLabelText('Source label (required)');

    expect(screen.getByText('(required)')).toBeInTheDocument();
    expect(sourceLabel).toHaveAttribute('required');
    expect(sourceLabel).toHaveAttribute('aria-required', 'true');
    expect(sourceLabel).toHaveAttribute('placeholder', 'e.g. FY2025 Benchmark submission');
    expect(sourceLabel).toHaveAttribute('maxLength', '255');

    await user.upload(screen.getByLabelText('CSV file'), makeCsvFile('values.csv'));
    await screen.findByText('1 CSV answer rows loaded.');
    await user.type(sourceLabel, '   ');

    expect(sourceLabel).toHaveValue('   ');
    expect(screen.getByRole('button', { name: 'Preview import' })).toBeDisabled();
    expect(screen.getByText('Add a source label to preview this import.')).toBeInTheDocument();
  });

  it('starts without a selected year and clears a preview when the year changes', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    previewImport.mockResolvedValue({
      surveyYear: 2025, sourceLabel: 'FY2025 source', overwrite: false,
      matchedCount: 1, unknownCount: 0, typeErrorCount: 0, existingChanges: 0, items: [],
    });
    const user = userEvent.setup();
    renderPanel();
    const yearSelect = screen.getByLabelText('Published survey year');

    expect(yearSelect).toHaveValue('');
    expect(screen.getByRole('option', { name: 'Choose a year…' })).toBeInTheDocument();
    await user.selectOptions(yearSelect, '2025');
    await user.type(screen.getByLabelText('Source label (required)'), 'FY2025 source');
    await user.upload(screen.getByLabelText('CSV file'), makeCsvFile('values.csv'));
    await screen.findByText('1 CSV answer rows loaded.');
    await user.click(screen.getByRole('button', { name: 'Preview import' }));
    expect(await screen.findByText(/Matched 1 · unknown IDs 0 · type errors 0/)).toBeInTheDocument();

    await user.selectOptions(yearSelect, '2026');

    expect(screen.queryByText(/Matched 1 · unknown IDs 0 · type errors 0/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Confirm import' })).toBeDisabled();
  });

  it('warns about a filename year mismatch without blocking Preview', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    const user = userEvent.setup();
    renderPanel();
    await user.selectOptions(screen.getByLabelText('Published survey year'), '2026');
    await user.type(screen.getByLabelText('Source label (required)'), 'FY2025 source');
    const csvInput = screen.getByLabelText('CSV file');

    await user.upload(csvInput, makeCsvFile('fy2025-values.csv'));
    await screen.findByText('1 CSV answer rows loaded.');
    const mismatch = 'This file looks like FY2025 data, but the selected survey year is 2026.';
    expect(screen.getByText(mismatch).closest('p')).toHaveAttribute('role', 'status');
    expect(screen.getByRole('button', { name: 'Preview import' })).toBeEnabled();

    await user.upload(csvInput, makeCsvFile('2026 values.csv'));
    await screen.findByText('1 CSV answer rows loaded.');
    expect(screen.queryByText(mismatch)).not.toBeInTheDocument();

    await user.upload(csvInput, makeCsvFile('values.csv'));
    await screen.findByText('1 CSV answer rows loaded.');
    expect(screen.queryByText(mismatch)).not.toBeInTheDocument();
  });

  it.each([
    [2, 1, 'Confirm is unavailable: 2 rows have unknown IDs and 1 row has type errors. Check the survey year, or fix those rows in the CSV.'],
    [1, 0, 'Confirm is unavailable: 1 row has unknown IDs. Check the survey year, or fix those rows in the CSV.'],
    [0, 2, 'Confirm is unavailable: 2 rows have type errors. Check the survey year, or fix those rows in the CSV.'],
  ])('explains why Confirm is disabled when unknown IDs=%i and type errors=%i', async (unknownCount, typeErrorCount, expectedHint) => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    previewImport.mockResolvedValue({
      surveyYear: 2025, sourceLabel: 'FY2025 source', overwrite: false,
      matchedCount: 1, unknownCount, typeErrorCount, existingChanges: 0, items: [],
    });
    const user = userEvent.setup();
    renderPanel();
    await user.selectOptions(screen.getByLabelText('Published survey year'), '2025');
    await user.type(screen.getByLabelText('Source label (required)'), 'FY2025 source');
    await user.upload(screen.getByLabelText('CSV file'), makeCsvFile('values.csv'));
    await screen.findByText('1 CSV answer rows loaded.');
    await user.click(screen.getByRole('button', { name: 'Preview import' }));
    await screen.findByText(new RegExp(`unknown IDs ${unknownCount} · type errors ${typeErrorCount}`));

    const confirmButton = screen.getByRole('button', { name: 'Confirm import' });
    expect(confirmButton).toBeDisabled();
    expect(screen.getByText(expectedHint).tagName).toBe('P');
    expect(confirmButton).toHaveAttribute('aria-describedby', 'acrl-confirm-import-hint');
  });
});
