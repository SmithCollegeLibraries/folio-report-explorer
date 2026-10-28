import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlNotesImportPanel from './AcrlNotesImportPanel';
import type { AcrlReviewResponse, AcrlSurveyYear } from '../types/acrl';

const { getReview, previewNotes, confirmNotes } = vi.hoisted(() => ({
  getReview: vi.fn(),
  previewNotes: vi.fn(),
  confirmNotes: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlReview: getReview,
  previewAcrlNotesImport: previewNotes,
  confirmAcrlNotesImport: confirmNotes,
}));

afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});

const years: AcrlSurveyYear[] = [{
  surveyYear: 2025, status: 'published', sourceFileName: null, surveyPromptCount: 1, trendsPromptCount: 0,
  surveyItemCount: 1, trendsItemCount: 0, totalItemCount: 1, publishedAt: '2026-01-01',
}];

const review: AcrlReviewResponse = {
  surveyYear: 2025, previousYear: null, status: 'published', items: [], previousQuestions: [], unresolvedCount: 0, canPublish: false,
};

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AcrlNotesImportPanel years={years} /></QueryClientProvider>);
}

describe('ACRL collection notes import', () => {
  it('shows file reading, row checking, importing, and a persistent result', async () => {
    getReview.mockResolvedValue(review);
    let finishRead!: (contents: string) => void;
    let finishPreview!: (value: unknown) => void;
    let finishImport!: (value: unknown) => void;
    previewNotes.mockReturnValue(new Promise((resolve) => { finishPreview = resolve; }));
    confirmNotes.mockReturnValue(new Promise((resolve) => { finishImport = resolve; }));
    const user = userEvent.setup();
    renderPanel();
    const file = new File(['pending'], 'notes.csv', { type: 'text/csv' });
    Object.defineProperty(file, 'text', { value: () => new Promise<string>((resolve) => { finishRead = resolve; }) });
    const input = screen.getByLabelText('Collection notes CSV');

    await user.upload(input, file);
    expect(await screen.findByText('Reading file…')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Import collection notes' })).toHaveAttribute('aria-busy', 'true');
    expect(input).toBeDisabled();
    expect(input).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Preview notes import' })).toBeDisabled();

    finishRead('question_id,part,note,source\n60,A,Synthetic note,Annual report');
    await screen.findByText(/1 note rows loaded/);
    await user.click(screen.getByRole('button', { name: 'Preview notes import' }));
    expect(screen.getByText('Checking 1 row…')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Preview notes import' })).toBeDisabled();
    finishPreview({ surveyYear: 2025, matchedCount: 1, unknownCount: 0, invalidCount: 0, items: [] });
    await screen.findByText(/Matched 1 · unknown 0 · invalid 0/);

    await user.click(screen.getByRole('button', { name: 'Confirm notes import' }));
    expect(screen.getByText('Importing…')).toBeInTheDocument();
    expect(input).toBeDisabled();
    finishImport({ preview: {}, result: { imported: 1, unchanged: 0, skipped: 0 } });
    expect(await screen.findByText('Imported 1; unchanged 0; skipped 0.')).toBeInTheDocument();
    expect(input).toBeEnabled();
    expect(screen.getByRole('region', { name: 'Import collection notes' })).toHaveAttribute('aria-busy', 'false');
  });

  it('announces a preview failure and lets the user choose the file again', async () => {
    getReview.mockResolvedValue(review);
    previewNotes.mockRejectedValue(new Error('Synthetic preview failure.'));
    const user = userEvent.setup();
    renderPanel();
    const file = new File(['question_id,part,note,source\n60,A,Note,Report'], 'notes.csv', { type: 'text/csv' });
    const read = vi.fn(async () => 'question_id,part,note,source\n60,A,Note,Report');
    Object.defineProperty(file, 'text', { value: read });
    const input = screen.getByLabelText('Collection notes CSV');

    await user.upload(input, file);
    await user.click(await screen.findByRole('button', { name: 'Preview notes import' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not preview this notes import.');
    expect(input).toBeEnabled();
    await user.upload(input, file);
    expect(read).toHaveBeenCalledTimes(2);
  });
});
