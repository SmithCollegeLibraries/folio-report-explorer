import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlPage from './Acrl';

const { listYears, getThreshold, parseWorksheetFile, uploadWorksheet } = vi.hoisted(() => ({
  listYears: vi.fn(),
  getThreshold: vi.fn(),
  parseWorksheetFile: vi.fn(),
  uploadWorksheet: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  listAcrlCatalogueYears: listYears,
  getAcrlReview: vi.fn(),
  saveAcrlLinks: vi.fn(),
  publishAcrlYear: vi.fn(),
  uploadAcrlWorksheet: uploadWorksheet,
  confirmAcrlImport: vi.fn(),
  getAcrlThreshold: getThreshold,
  saveAcrlThreshold: vi.fn(),
  previewAcrlImport: vi.fn(),
}));
vi.mock('../services/acrlWorksheetParser', () => ({ parseWorksheetFile, sha256ForFile: vi.fn() }));

afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});

describe('ACRL worksheet calculation preview', () => {
  it('shows page reading, row checking, and draft saving states and retains success', async () => {
    let finishYears!: (years: never[]) => void;
    let finishParse!: (result: unknown) => void;
    let finishUpload!: (result: unknown) => void;
    listYears.mockReturnValue(new Promise((resolve) => { finishYears = resolve; }));
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    parseWorksheetFile.mockImplementation((_file, onPageProgress) => new Promise((resolve) => {
      onPageProgress(1, 2);
      finishParse = resolve;
    }));
    uploadWorksheet.mockReturnValue(new Promise((resolve) => { finishUpload = resolve; }));
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const user = userEvent.setup();
    render(<QueryClientProvider client={client}><AcrlPage /></QueryClientProvider>);
    const input = screen.getByLabelText('Worksheet PDF');
    const file = new File(['synthetic'], 'worksheet.pdf', { type: 'application/pdf' });

    await user.upload(input, file);
    expect(await screen.findByText('Reading page 1 of 2…')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Upload worksheet' })).toHaveAttribute('aria-busy', 'true');
    expect(input).toBeDisabled();
    expect(input).toHaveValue('');
    finishParse({
      items: [{ sheet: 'survey', question_id: '60', part: 'A', section: 'Collections', prompt: 'Synthetic question', answer_type: 'text', required: 'no', calculated_from: null, survey_order: 1 }],
      unresolved: [], unresolvedCalculations: [],
    });
    expect(await screen.findByText('Checking 1 row…')).toBeInTheDocument();
    finishYears([]);
    expect(await screen.findByText('Saving draft…')).toBeInTheDocument();
    expect(uploadWorksheet).toHaveBeenCalledOnce();
    expect(input).toBeDisabled();
    finishUpload({
      success: true,
      surveyYear: { surveyYear: 2026, status: 'draft', sourceFileName: 'worksheet.pdf', surveyPromptCount: 1, trendsPromptCount: 0, surveyItemCount: 1, trendsItemCount: 0, totalItemCount: 1, publishedAt: null },
      review: { surveyYear: 2026, previousYear: null, status: 'draft', items: [], previousQuestions: [], unresolvedCount: 0, canPublish: false },
    });
    expect(await screen.findByText('Draft saved for FY 2026.')).toBeInTheDocument();
    expect(input).toBeEnabled();
    expect(screen.getByRole('region', { name: 'Upload worksheet' })).toHaveAttribute('aria-busy', 'false');
  });

  it('shows parsing errors as alerts and keeps the input available for retry', async () => {
    listYears.mockResolvedValue([]);
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    parseWorksheetFile.mockRejectedValue(new Error('Synthetic PDF failure.'));
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const user = userEvent.setup();
    render(<QueryClientProvider client={client}><AcrlPage /></QueryClientProvider>);
    const input = screen.getByLabelText('Worksheet PDF');
    const file = new File(['synthetic'], 'worksheet.pdf', { type: 'application/pdf' });

    await user.upload(input, file);

    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic PDF failure.');
    expect(input).toBeEnabled();
    expect(input).toHaveValue('');
    await user.upload(input, file);
    expect(parseWorksheetFile).toHaveBeenCalledTimes(2);
  });

  it('shows unresolved calculation IDs and waits for review before uploading', async () => {
    listYears.mockResolvedValue([]);
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    parseWorksheetFile.mockResolvedValue({
      items: [{
        sheet: 'survey', question_id: '44', part: 'A', section: 'Library collections', prompt: 'Synthetic total',
        answer_type: 'number', required: 'yes', calculated_from: 'sum of 40, 99', survey_order: 1,
      }],
      unresolved: [],
      unresolvedCalculations: ['44A: 99'],
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const user = userEvent.setup();
    render(<QueryClientProvider client={client}><AcrlPage /></QueryClientProvider>);

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['synthetic'], 'worksheet.pdf', { type: 'application/pdf' }));

    expect(await screen.findByRole('heading', { name: 'Calculations that need checking' })).toBeInTheDocument();
    expect(screen.getByText('44A: 99')).toBeInTheDocument();
    expect(uploadWorksheet).not.toHaveBeenCalled();
  });

  it('previews detected Trends layouts, choices, rows, and free-text fallback warnings', async () => {
    listYears.mockResolvedValue([]);
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    parseWorksheetFile.mockResolvedValue({
      items: [
        { sheet: 'trends', question_id: 'T1', part: '', section: 'Trends', prompt: 'Rating question', answer_type: 'choice', required: 'no', calculated_from: null, survey_order: 1, answer_options: { kind: 'rating', rows: [], choices: ['Much higher', 'Higher', 'Same', 'Lower', 'Much lower'], allowOther: false } },
        { sheet: 'trends', question_id: 'T3', part: '', section: 'Trends', prompt: 'Check all that apply', answer_type: 'choice', required: 'no', calculated_from: null, survey_order: 3, answer_options: { kind: 'checkbox', rows: [], choices: ['Policy', 'Other'], allowOther: true } },
        { sheet: 'trends', question_id: 'T5', part: '', section: 'Trends', prompt: 'Grid question', answer_type: 'choice', required: 'no', calculated_from: null, survey_order: 5, answer_options: { kind: 'grid', rows: ['Funding', 'Other'], choices: ['Yes', 'No', 'Don’t know'], allowOther: true } },
        { sheet: 'trends', question_id: 'T10', part: '', section: 'Trends', prompt: 'Unknown question', answer_type: 'text', required: 'no', calculated_from: null, survey_order: 10, answer_options: { kind: 'text', rows: [], choices: [], allowOther: false } },
      ],
      unresolved: ['Review this worksheet line'],
      layoutWarnings: ['T10: answer layout not recognized; using free text.'],
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const user = userEvent.setup();
    render(<QueryClientProvider client={client}><AcrlPage /></QueryClientProvider>);

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['synthetic'], 'worksheet.pdf', { type: 'application/pdf' }));

    const t1 = (await screen.findByText('T1', { exact: true })).closest('tr') as HTMLTableRowElement;
    expect(within(t1).getByText('Rating scale')).toBeInTheDocument();
    expect(within(t1).getByText('5 choices')).toBeInTheDocument();
    const t5 = screen.getByText('T5', { exact: true }).closest('tr') as HTMLTableRowElement;
    expect(within(t5).getByText('Grid')).toBeInTheDocument();
    expect(within(t5).getByText('2 rows · 3 choices')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Trends questions using free text' })).toBeInTheDocument();
    expect(screen.getByText('T10: answer layout not recognized; using free text.')).toBeInTheDocument();
    expect(screen.getAllByText('Other text response')).toHaveLength(2);
  });
});
