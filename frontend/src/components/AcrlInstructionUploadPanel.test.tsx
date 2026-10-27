import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlInstructionUploadPanel from './AcrlInstructionUploadPanel';
import type { AcrlInstructionParseResult, AcrlReviewResponse, AcrlSurveyYear } from '../types/acrl';

const { getReview, parseInstructions, saveInstructions, sha256ForFile } = vi.hoisted(() => ({
  getReview: vi.fn(),
  parseInstructions: vi.fn(),
  saveInstructions: vi.fn(),
  sha256ForFile: vi.fn(),
}));

vi.mock('../api/acrl', () => ({ getAcrlReview: getReview, saveAcrlInstructions: saveInstructions }));
vi.mock('../services/acrlInstructionParser', () => ({ parseInstructionFile: parseInstructions }));
vi.mock('../services/acrlWorksheetParser', () => ({ sha256ForFile }));

afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});

const years: AcrlSurveyYear[] = [{
  surveyYear: 2025, status: 'published', sourceFileName: null, surveyPromptCount: 1, trendsPromptCount: 0,
  surveyItemCount: 1, trendsItemCount: 0, totalItemCount: 1, publishedAt: '2026-01-01',
}];

const question = {
  sheet: 'survey' as const, question_id: '60', part: 'A', section: 'Collections', prompt: 'Synthetic question',
  answer_type: 'text' as const, required: 'no' as const, calculated_from: null, survey_order: 1,
};

const review: AcrlReviewResponse = {
  surveyYear: 2025, previousYear: null, status: 'published',
  items: [{ status: 'new', matchKind: null, score: 1, previous: null, current: question, topical: false, decision: null, linkId: null }],
  previousQuestions: [], unresolvedCount: 0, canPublish: false,
};

const parsed: AcrlInstructionParseResult = {
  blocks: [{ blockKey: 'question:60A', heading: 'Question 60A', text: 'Synthetic instructions', kind: 'question' }],
  links: [{ sheet: 'survey', question_id: '60', part: 'A', blockKey: 'question:60A' }],
  assignments: [], missingQuestionKeys: [], duplicateBlockKeys: [], coverage: { individual: 1, grouped: 0, missing: 0 },
};

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AcrlInstructionUploadPanel years={years} /></QueryClientProvider>);
}

describe('ACRL instruction upload', () => {
  it('reports PDF pages, checks the catalogue, saves, and keeps a completion result', async () => {
    let finishParse!: (result: AcrlInstructionParseResult) => void;
    let finishCheck!: (result: AcrlReviewResponse) => void;
    let finishSave!: (result: { success: boolean; surveyYear: number; blockCount: number; linkCount: number }) => void;
    getReview.mockResolvedValueOnce(review).mockReturnValueOnce(new Promise((resolve) => { finishCheck = resolve; }));
    parseInstructions.mockImplementation((_file, _questions, onPageProgress) => new Promise((resolve) => {
      onPageProgress(1, 3);
      finishParse = resolve;
    }));
    sha256ForFile.mockResolvedValue('synthetic-hash');
    saveInstructions.mockReturnValue(new Promise((resolve) => { finishSave = resolve; }));
    const user = userEvent.setup();
    renderPanel();
    const input = await screen.findByLabelText('Instructions PDF');
    await waitFor(() => expect(input).toBeEnabled());
    const file = new File(['synthetic'], 'instructions.pdf', { type: 'application/pdf' });

    await user.upload(input, file);
    expect(await screen.findByText('Reading page 1 of 3…')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Survey instructions' })).toHaveAttribute('aria-busy', 'true');
    expect(input).toBeDisabled();
    expect(input).toHaveValue('');
    finishParse(parsed);
    expect(await screen.findByText('Preview ready: 1 instruction block and 1 question link.')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Save instructions' }));
    expect(await screen.findByText('Checking question catalogue…')).toBeInTheDocument();
    expect(input).toBeDisabled();
    finishCheck(review);
    expect(await screen.findByText('Saving instructions…')).toBeInTheDocument();
    finishSave({ success: true, surveyYear: 2025, blockCount: 1, linkCount: 1 });
    expect(await screen.findByText('Saved 1 instruction block and 1 question link.')).toBeInTheDocument();
    expect(input).toBeEnabled();
    expect(screen.getByRole('region', { name: 'Survey instructions' })).toHaveAttribute('aria-busy', 'false');
  });

  it('announces PDF parsing errors and allows choosing the same PDF again', async () => {
    getReview.mockResolvedValue(review);
    parseInstructions.mockRejectedValue(new Error('Synthetic PDF failure.'));
    const user = userEvent.setup();
    renderPanel();
    const input = await screen.findByLabelText('Instructions PDF');
    await waitFor(() => expect(input).toBeEnabled());
    const file = new File(['synthetic'], 'instructions.pdf', { type: 'application/pdf' });

    await user.upload(input, file);

    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic PDF failure.');
    expect(input).toBeEnabled();
    expect(input).toHaveValue('');
    await user.upload(input, file);
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic PDF failure.');
    await waitFor(() => expect(parseInstructions).toHaveBeenCalledTimes(2));
  });

  it('labels catalogue-check failures accurately and does not call the save API', async () => {
    let finishRetry!: (result: AcrlReviewResponse) => void;
    getReview.mockResolvedValueOnce(review)
      .mockRejectedValueOnce(new Error('Synthetic catalogue failure.'))
      .mockReturnValueOnce(new Promise((resolve) => { finishRetry = resolve; }));
    parseInstructions.mockResolvedValue(parsed);
    sha256ForFile.mockResolvedValue('synthetic-hash');
    saveInstructions.mockResolvedValue({ success: true, surveyYear: 2025, blockCount: 1, linkCount: 1 });
    const user = userEvent.setup();
    renderPanel();
    const input = await screen.findByLabelText('Instructions PDF');
    await waitFor(() => expect(input).toBeEnabled());

    await user.upload(input, new File(['synthetic'], 'instructions.pdf', { type: 'application/pdf' }));
    await screen.findByText('Preview ready: 1 instruction block and 1 question link.');
    await user.click(screen.getByRole('button', { name: 'Save instructions' }));

    expect(await screen.findByText('Could not check the selected year’s question catalogue: Synthetic catalogue failure.')).toBeInTheDocument();
    expect(screen.queryByText('Could not load the selected year’s question catalogue.')).not.toBeInTheDocument();
    expect(saveInstructions).not.toHaveBeenCalled();
    expect(input).toBeEnabled();

    await user.click(screen.getByRole('button', { name: 'Save instructions' }));
    expect(await screen.findByText('Checking question catalogue…')).toBeInTheDocument();
    expect(screen.queryByText('Could not load the selected year’s question catalogue.')).not.toBeInTheDocument();
    finishRetry(review);
    await waitFor(() => expect(saveInstructions).toHaveBeenCalledOnce());
  });
});
