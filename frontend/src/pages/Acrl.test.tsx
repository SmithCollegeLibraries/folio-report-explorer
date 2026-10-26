import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlPage from './Acrl';
import AcrlReviewPanel from '../components/AcrlReviewPanel';
import type { AcrlQuestionPart, AcrlReviewResponse } from '../types/acrl';

const { listYears, getReview, saveLinks, publishYear, uploadWorksheet, parseWorksheetFile, sha256ForFile } = vi.hoisted(() => ({
  listYears: vi.fn(),
  getReview: vi.fn(),
  saveLinks: vi.fn(),
  publishYear: vi.fn(),
  uploadWorksheet: vi.fn(),
  parseWorksheetFile: vi.fn(),
  sha256ForFile: vi.fn(),
}));

vi.mock('../api/acrl', () => ({ listAcrlCatalogueYears: listYears, getAcrlReview: getReview, saveAcrlLinks: saveLinks, publishAcrlYear: publishYear, uploadAcrlWorksheet: uploadWorksheet }));
vi.mock('../services/acrlWorksheetParser', () => ({ parseWorksheetFile, sha256ForFile }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderAcrl() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}><AcrlPage /></QueryClientProvider>);
}

describe('ACRL review panel bulk decisions', () => {
  it('selects high-score reworded and renumbered matches, excludes low-score rows, and keeps single approval', async () => {
    const question = (id: number, questionId: string, prompt: string): AcrlQuestionPart => ({
      id,
      sheet: 'survey',
      question_id: questionId,
      part: '',
      section: 'Finance',
      prompt,
      answer_type: 'currency',
      required: 'yes',
      calculated_from: null,
      survey_order: id,
    });
    const item = (status: 'reworded' | 'renumbered', score: number, previousId: string, currentId: string): AcrlReviewResponse['items'][number] => ({
      status,
      matchKind: status,
      score,
      decision: null,
      linkId: null,
      topical: false,
      previous: question(Number(previousId.replace(/\D/g, '')) || 1, previousId, `Previous wording ${previousId}`),
      current: question(Number(currentId.replace(/\D/g, '')) || 2, currentId, `Current wording ${currentId}`),
    });
    const review: AcrlReviewResponse = {
      surveyYear: 2026,
      previousYear: 2025,
      status: 'draft',
      unresolvedCount: 3,
      canPublish: false,
      previousQuestions: [],
      items: [
        item('reworded', 0.78, '7', '7'),
        item('reworded', 0.49, '8', '8'),
        item('renumbered', 0.68, '110', '101'),
      ],
    } as AcrlReviewResponse;
    const onSave = vi.fn();
    const user = userEvent.setup();
    render(<AcrlReviewPanel review={review} saving={false} publishing={false} onSave={onSave} onPublish={vi.fn()} />);

    await user.click(screen.getByRole('checkbox', { name: 'Select all high-score Reworded matches' }));
    await user.click(screen.getByRole('checkbox', { name: 'Select all high-score Renumbered matches' }));

    expect(screen.getByRole('checkbox', { name: 'Select 7 reworded match' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Select 8 reworded match' })).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: 'Select 101 renumbered match' })).toBeChecked();

    await user.click(screen.getByRole('button', { name: 'Approve selected' }));
    expect(onSave).toHaveBeenNthCalledWith(1, [
      expect.objectContaining({ previous_question_id: '7', current_question_id: '7', decision: 'approved' }),
      expect.objectContaining({ previous_question_id: '110', current_question_id: '101', decision: 'approved' }),
    ]);
    expect(onSave.mock.calls[0][0]).toHaveLength(2);

    await user.click(screen.getAllByRole('button', { name: 'Approve' })[1]);
    expect(onSave).toHaveBeenNthCalledWith(2, [expect.objectContaining({ previous_question_id: '8', current_question_id: '8', decision: 'approved' })]);
  });
});

describe('ACRL catalogue page', () => {
  it('shows grouped annual changes and approves unchanged high-score matches in bulk', async () => {
    listYears.mockResolvedValue([
      { surveyYear: 2026, status: 'draft', sourceFileName: 'worksheet.pdf', surveyPromptCount: 73, trendsPromptCount: 9, surveyItemCount: 73, trendsItemCount: 9, totalItemCount: 82, publishedAt: null },
      { surveyYear: 2025, status: 'published', sourceFileName: null, surveyPromptCount: 69, trendsPromptCount: 15, surveyItemCount: 79, trendsItemCount: 15, totalItemCount: 94, publishedAt: '2026-01-01' },
    ]);
    getReview.mockResolvedValue({
      surveyYear: 2026, previousYear: 2025, status: 'draft', unresolvedCount: 1, canPublish: false, previousQuestions: [],
      items: [
        { status: 'unchanged', matchKind: 'same', score: 0.98, decision: null, linkId: null, topical: false,
          previous: { id: 1, sheet: 'survey', question_id: '1', part: 'A', section: 'Staffing', prompt: 'Librarians FTE', answer_type: 'number', required: 'yes', calculated_from: null, survey_order: 1 },
          current: { id: 2, sheet: 'survey', question_id: '1', part: 'A', section: 'Staffing', prompt: 'Librarians FTE', answer_type: 'number', required: 'yes', calculated_from: null, survey_order: 1 } },
        { status: 'new', matchKind: null, score: 0, decision: null, linkId: null, topical: false, previous: null,
          current: { id: 3, sheet: 'survey', question_id: '6', part: 'C', section: 'Staffing', prompt: 'Total salaries when no breakdown is available', answer_type: 'currency', required: 'yes', calculated_from: null, survey_order: 3 } },
        { status: 'removed', matchKind: null, score: 0, decision: null, linkId: null, topical: false,
          previous: { id: 4, sheet: 'survey', question_id: '20a', part: '', section: 'Expenses', prompt: 'Electronic materials expenses', answer_type: 'currency', required: 'no', calculated_from: null, survey_order: 20 }, current: null },
      ],
    });
    saveLinks.mockResolvedValue({ surveyYear: 2026, previousYear: 2025, status: 'draft', unresolvedCount: 0, canPublish: true, previousQuestions: [], items: [] });
    const user = userEvent.setup();
    renderAcrl();

    await user.click(await screen.findByRole('button', { name: 'Review 2026' }));
    expect(await screen.findByRole('heading', { name: '2025 → 2026 review' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'New' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Removed' })).toBeInTheDocument();
    expect(screen.getByText('Current · 6C')).toBeInTheDocument();
    expect(screen.getByText('Previous · 20a')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Approve unchanged high-score matches' }));
    await waitFor(() => expect(saveLinks).toHaveBeenCalledWith(2026, [expect.objectContaining({ decision: 'approved', previous_question_id: '1', current_question_id: '1' })]));
  });

  it('shows unresolved PDF lines before upload and blocks saving until they are placed', async () => {
    listYears.mockResolvedValue([]);
    parseWorksheetFile.mockResolvedValue({
      items: [{ sheet: 'survey', question_id: '1', part: 'A', section: 'Staffing', prompt: 'Librarians FTE', answer_type: 'number', required: 'yes', calculated_from: null, survey_order: 1 }],
      unresolved: ['*? unclear source line'],
    });
    uploadWorksheet.mockResolvedValue({});
    const user = userEvent.setup();
    renderAcrl();
    const file = new File(['worksheet'], 'worksheet.pdf', { type: 'application/pdf' });
    await user.upload(screen.getByLabelText('Worksheet PDF'), file);

    expect(await screen.findByText('*? unclear source line')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled();
    expect(uploadWorksheet).not.toHaveBeenCalled();
  });
});
