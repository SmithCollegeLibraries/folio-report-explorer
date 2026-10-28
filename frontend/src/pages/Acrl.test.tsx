import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlPage from './Acrl';
import AcrlReviewPanel from '../components/AcrlReviewPanel';
import type { AcrlQuestionPart, AcrlReviewResponse, AcrlSurveyYear } from '../types/acrl';

const { listYears, getReview, saveLinks, publishYear, uploadWorksheet, parseWorksheetFile, sha256ForFile, getThreshold, saveThreshold, previewImport, confirmImport } = vi.hoisted(() => ({
  listYears: vi.fn(),
  getReview: vi.fn(),
  saveLinks: vi.fn(),
  publishYear: vi.fn(),
  uploadWorksheet: vi.fn(),
  parseWorksheetFile: vi.fn(),
  sha256ForFile: vi.fn(),
  getThreshold: vi.fn().mockResolvedValue({ thresholdPercent: 10 }),
  saveThreshold: vi.fn().mockResolvedValue({ thresholdPercent: 10 }),
  previewImport: vi.fn(),
  confirmImport: vi.fn(),
}));
let scrollIntoViewDescriptor: PropertyDescriptor | undefined;

vi.mock('../api/acrl', () => ({ listAcrlCatalogueYears: listYears, getAcrlReview: getReview, saveAcrlLinks: saveLinks, publishAcrlYear: publishYear, uploadAcrlWorksheet: uploadWorksheet, getAcrlThreshold: getThreshold, saveAcrlThreshold: saveThreshold, previewAcrlImport: previewImport, confirmAcrlImport: confirmImport }));
vi.mock('../services/acrlWorksheetParser', () => ({ parseWorksheetFile, sha256ForFile }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.restoreAllMocks();
  if (scrollIntoViewDescriptor) Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', scrollIntoViewDescriptor);
  else Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView');
  scrollIntoViewDescriptor = undefined;
});

function renderAcrl() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}><AcrlPage /></QueryClientProvider>);
}

function makeQuestion(sheet: 'survey' | 'trends', questionId: string): AcrlQuestionPart {
  return {
    sheet,
    question_id: questionId,
    part: '',
    section: 'Staffing',
    prompt: `${sheet} question ${questionId}`,
    answer_type: 'text',
    required: 'yes',
    calculated_from: null,
    survey_order: 1,
  };
}

function makeYear(surveyYear: number, status: 'draft' | 'published' = 'draft'): AcrlSurveyYear {
  return {
    surveyYear,
    status,
    sourceFileName: status === 'draft' ? 'worksheet.pdf' : null,
    surveyPromptCount: 1,
    trendsPromptCount: 1,
    surveyItemCount: 1,
    trendsItemCount: 1,
    totalItemCount: 2,
    publishedAt: status === 'published' ? '2026-01-01' : null,
  };
}

function makeReview(): AcrlReviewResponse {
  return {
    surveyYear: 2026,
    previousYear: 2025,
    status: 'draft',
    items: [],
    previousQuestions: [],
    unresolvedCount: 0,
    canPublish: true,
  };
}

function makeUploadResponse(review = makeReview()) {
  return { success: true, surveyYear: makeYear(2026), review };
}

describe('ACRL review panel bulk decisions', () => {
  it('approves every undecided row by status and confirms weak text matches', async () => {
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
    const item = (status: 'unchanged' | 'reworded' | 'renumbered', score: number, previousId: string, currentId: string): AcrlReviewResponse['items'][number] => ({
      status,
      matchKind: status === 'unchanged' ? 'same' : status,
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
        item('unchanged', 0.45, '9', '9'),
      ],
    } as AcrlReviewResponse;
    const onSave = vi.fn();
    const user = userEvent.setup();
    render(<AcrlReviewPanel review={review} saving={false} publishing={false} onSave={onSave} onPublish={vi.fn()} />);

    const rewordedGroup = screen.getByRole('region', { name: 'Reworded survey questions' });
    await user.click(within(rewordedGroup).getByRole('button', { name: 'Approve all (2)' }));
    expect(screen.getByRole('alertdialog', { name: 'Confirm bulk approval for Reworded' })).toHaveTextContent(
      '1 of these have a weak text match (IDs 8). Approve all anyway?'
    );
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Cancel' }));
    expect(onSave).not.toHaveBeenCalled();

    await user.click(within(rewordedGroup).getByRole('button', { name: 'Approve all (2)' }));
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Approve all' }));
    expect(onSave).toHaveBeenNthCalledWith(1, [
      expect.objectContaining({ previous_question_id: '7', current_question_id: '7', decision: 'approved' }),
      expect.objectContaining({ previous_question_id: '8', current_question_id: '8', decision: 'approved' }),
    ]);
    expect(onSave.mock.calls[0][0]).toHaveLength(2);

    await user.click(within(screen.getByRole('region', { name: 'Renumbered survey questions' })).getByRole('button', { name: 'Approve all (1)' }));
    expect(onSave).toHaveBeenNthCalledWith(2, [expect.objectContaining({ previous_question_id: '110', current_question_id: '101', decision: 'approved' })]);
    await user.click(within(screen.getByRole('region', { name: 'Unchanged survey questions' })).getByRole('button', { name: 'Approve all (1)' }));
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Approve all' }));
    expect(onSave).toHaveBeenNthCalledWith(3, [expect.objectContaining({ previous_question_id: '9', current_question_id: '9', decision: 'approved' })]);
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve selected' })).not.toBeInTheDocument();
  });

  it('lets a draft decision be changed from the row', async () => {
    const review = makeReview();
    review.items = [{
      status: 'reworded', matchKind: 'reworded', score: 0.8, decision: 'rejected', linkId: 1, topical: false,
      previous: makeQuestion('survey', '7'), current: makeQuestion('survey', '7'),
    }];
    const onSave = vi.fn();
    const user = userEvent.setup();
    render(<AcrlReviewPanel review={review} saving={false} publishing={false} onSave={onSave} onPublish={vi.fn()} />);

    const group = screen.getByRole('region', { name: 'Reworded survey questions' });
    expect(within(group).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    await user.click(within(group).getByRole('button', { name: 'Change decision for 7' }));
    await user.click(within(group).getByRole('button', { name: 'Approve' }));

    expect(onSave).toHaveBeenCalledWith([expect.objectContaining({ previous_question_id: '7', current_question_id: '7', decision: 'approved' })]);
  });
});

describe('ACRL catalogue page', () => {
  it('shows grouped annual changes and approves unchanged matches in bulk', async () => {
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

    const currentYearCard = (await screen.findByRole('heading', { name: 'FY 2026' })).closest('article');
    expect(currentYearCard).not.toBeNull();
    await user.click(within(currentYearCard as HTMLElement).getByRole('button', { name: 'Open review' }));
    expect(await screen.findByRole('heading', { name: 'FY 2026 compared with FY 2025' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'New survey questions' }).querySelector('summary')).toHaveTextContent(/^New/);
    expect(screen.getByRole('region', { name: 'Removed survey questions' }).querySelector('summary')).toHaveTextContent(/^Removed/);
    expect(screen.getByText('Current · 6C')).toBeInTheDocument();
    expect(screen.getByText('Previous · 20a')).toBeInTheDocument();
    await user.click(within(screen.getByRole('region', { name: 'Unchanged survey questions' })).getByRole('button', { name: 'Approve all (1)' }));
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
    expect(screen.getByRole('button', { name: 'Continue and compare' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save draft' })).not.toBeInTheDocument();
    expect(uploadWorksheet).not.toHaveBeenCalled();
  });

  it('automatically saves a clean parse, compares the prior year, and focuses the review heading only on open', async () => {
    listYears.mockResolvedValue([makeYear(2025, 'published')]);
    const review = makeReview();
    review.unresolvedCount = 1;
    review.canPublish = false;
    review.items = [{
      status: 'reworded', matchKind: 'reworded', score: 0.8, decision: null, linkId: null, topical: false,
      previous: makeQuestion('survey', '7'), current: makeQuestion('survey', '7'),
    }];
    getReview.mockResolvedValue(review);
    parseWorksheetFile.mockResolvedValue({ items: [makeQuestion('survey', '1')], unresolved: [] });
    sha256ForFile.mockResolvedValue('worksheet-hash');
    uploadWorksheet.mockResolvedValue(makeUploadResponse(review));
    const savedReview = { ...review, unresolvedCount: 0, canPublish: true, items: [{ ...review.items[0], decision: 'approved' as const }] };
    saveLinks.mockResolvedValue(savedReview);
    const scrollIntoView = vi.fn();
    scrollIntoViewDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: scrollIntoView });
    const user = userEvent.setup();
    renderAcrl();

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['worksheet'], 'worksheet.pdf', { type: 'application/pdf' }));

    expect(await screen.findByText('FY 2026 compared with FY 2025')).toBeInTheDocument();
    expect(uploadWorksheet).toHaveBeenCalledWith({
      surveyYear: 2026,
      fileName: 'worksheet.pdf',
      sha256: 'worksheet-hash',
      items: [makeQuestion('survey', '1')],
      replacePublished: false,
    });
    expect(screen.getByRole('heading', { name: 'FY 2026 compared with FY 2025' })).toHaveFocus();
    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'start', behavior: 'smooth' });
    expect(screen.queryByRole('button', { name: 'Save draft' })).not.toBeInTheDocument();
    expect(screen.getByText('Your upload is kept as a draft. Nothing is final until you publish it.')).toBeInTheDocument();

    await user.click(within(screen.getByRole('region', { name: 'Reworded survey questions' })).getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(saveLinks).toHaveBeenCalledTimes(1));
    const decidedGroup = await screen.findByRole('group', { name: 'Reworded questions · All 1 approved' });
    await waitFor(() => expect(decidedGroup.querySelector('summary')).toHaveFocus());
    expect(scrollIntoView).toHaveBeenCalledTimes(1);
  });

  it('shows reading and saving progress while a clean worksheet is processed', async () => {
    listYears.mockResolvedValue([]);
    getReview.mockResolvedValue(makeReview());
    let resolveParse!: (result: { items: AcrlQuestionPart[]; unresolved: string[] }) => void;
    let resolveUpload!: (result: ReturnType<typeof makeUploadResponse>) => void;
    parseWorksheetFile.mockReturnValue(new Promise((resolve) => { resolveParse = resolve; }));
    sha256ForFile.mockResolvedValue('worksheet-hash');
    uploadWorksheet.mockReturnValue(new Promise((resolve) => { resolveUpload = resolve; }));
    const user = userEvent.setup();
    renderAcrl();

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['worksheet'], 'worksheet.pdf', { type: 'application/pdf' }));
    expect(await screen.findByText('Reading file…')).toBeInTheDocument();

    await act(async () => resolveParse({ items: [makeQuestion('survey', '1')], unresolved: [] }));
    expect(await screen.findByText('Saving draft…')).toBeInTheDocument();
    await act(async () => resolveUpload(makeUploadResponse()));
    expect(await screen.findByText('FY 2026 compared with FY 2025')).toBeInTheDocument();
  });

  it('lets the user continue to comparison when the parser leaves lines unplaced', async () => {
    listYears.mockResolvedValue([]);
    getReview.mockResolvedValue(makeReview());
    parseWorksheetFile.mockResolvedValue({ items: [makeQuestion('survey', '1')], unresolved: ['unclear source line'] });
    sha256ForFile.mockResolvedValue('worksheet-hash');
    uploadWorksheet.mockResolvedValue(makeUploadResponse());
    const user = userEvent.setup();
    renderAcrl();

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['worksheet'], 'worksheet.pdf', { type: 'application/pdf' }));
    expect(await screen.findByText('unclear source line')).toBeInTheDocument();
    expect(uploadWorksheet).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Continue and compare' }));

    expect(await screen.findByText('FY 2026 compared with FY 2025')).toBeInTheDocument();
    expect(uploadWorksheet).toHaveBeenCalledTimes(1);
  });

  it('cancels an unresolved worksheet without saving it', async () => {
    listYears.mockResolvedValue([]);
    parseWorksheetFile.mockResolvedValue({ items: [makeQuestion('survey', '1')], unresolved: ['unclear source line'] });
    const user = userEvent.setup();
    renderAcrl();

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['worksheet'], 'worksheet.pdf', { type: 'application/pdf' }));
    expect(await screen.findByText('unclear source line')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByText('unclear source line')).not.toBeInTheDocument();
    expect(uploadWorksheet).not.toHaveBeenCalled();
  });

  it('asks before replacing a published year and uploads only after confirmation', async () => {
    listYears.mockResolvedValue([makeYear(2026, 'published')]);
    parseWorksheetFile.mockResolvedValue({ items: [makeQuestion('survey', '1')], unresolved: [] });
    sha256ForFile.mockResolvedValue('worksheet-hash');
    uploadWorksheet.mockResolvedValue(makeUploadResponse());
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    renderAcrl();
    expect(await screen.findByText('FY 2026')).toBeInTheDocument();

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['worksheet'], 'worksheet.pdf', { type: 'application/pdf' }));

    expect(await screen.findByText('FY 2026 compared with FY 2025')).toBeInTheDocument();
    expect(window.confirm).toHaveBeenCalledWith('FY 2026 is published. Replace it with this upload as a draft?');
    expect(uploadWorksheet).toHaveBeenCalledWith(expect.objectContaining({ surveyYear: 2026, replacePublished: true }));
  });

  it('does not save a published year when replacement is declined', async () => {
    listYears.mockResolvedValue([makeYear(2026, 'published')]);
    parseWorksheetFile.mockResolvedValue({ items: [makeQuestion('survey', '1')], unresolved: [] });
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    const user = userEvent.setup();
    renderAcrl();
    expect(await screen.findByText('FY 2026')).toBeInTheDocument();

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['worksheet'], 'worksheet.pdf', { type: 'application/pdf' }));

    await waitFor(() => expect(window.confirm).toHaveBeenCalledWith('FY 2026 is published. Replace it with this upload as a draft?'));
    expect(uploadWorksheet).not.toHaveBeenCalled();
    expect(screen.getByText(/Upload canceled/)).toBeInTheDocument();
    expect(screen.getByRole('spinbutton', { name: 'Survey year' })).toBeEnabled();
    expect(screen.getByLabelText('Worksheet PDF')).toBeEnabled();
  });

  it('summarizes survey changes, displays full new and shortened removed questions, and separates Trends', async () => {
    const question = (sheet: 'survey' | 'trends', questionId: string) => makeQuestion(sheet, questionId);
    const item = (status: AcrlReviewResponse['items'][number]['status'], previous: AcrlQuestionPart | null, current: AcrlQuestionPart | null): AcrlReviewResponse['items'][number] => ({
      status,
      matchKind: null,
      score: 0,
      previous,
      current,
      topical: false,
      decision: null,
      linkId: null,
    });
    const review: AcrlReviewResponse = {
      ...makeReview(),
      unresolvedCount: 2,
      canPublish: false,
      previousQuestions: [question('trends', 'T10')],
      items: [
        item('new', null, { ...question('survey', '6'), part: 'C', prompt: 'Total salaries and wages when an institution cannot report them separately' }),
        item('new', null, question('trends', 'T1')),
        item('removed', { ...question('survey', '20a'), prompt: 'Removed survey question wording that is more than eighty characters long so the summary truncates it after the expected boundary.' }, null),
        item('removed', question('trends', 'T10'), null),
        item('renumbered', question('survey', '3'), question('survey', '4')),
        item('reworded', question('trends', '5'), question('trends', '5')),
        item('unchanged', question('survey', '6'), question('survey', '6')),
      ],
    };
    const user = userEvent.setup();
    render(<AcrlReviewPanel review={review} saving={false} publishing={false} onSave={vi.fn()} onPublish={vi.fn()} />);

    expect(screen.getByRole('heading', { name: 'FY 2026 compared with FY 2025' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /1 renumbered/ })).toHaveAttribute('href', '#acrl-group-renumbered');
    expect(screen.getByRole('link', { name: /0 reworded/ })).toHaveAttribute('href', '#acrl-group-reworded');
    expect(screen.getByRole('link', { name: /1 unchanged/ })).toHaveAttribute('href', '#acrl-group-unchanged');
    expect(screen.getByRole('heading', { name: 'New survey questions (1)' })).toBeInTheDocument();
    expect(screen.getByText(/6C — Total salaries and wages when an institution cannot report them separately/)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Removed survey questions (1)' })).toBeInTheDocument();
    const removedSummary = within(screen.getByRole('region', { name: 'Removed survey questions (1)' })).getByRole('link');
    expect(removedSummary).toHaveTextContent(/^20a — Removed survey question wording that is more than eighty characters long so the…$/);
    expect(removedSummary.textContent?.length).toBeLessThanOrEqual(86);
    expect(screen.getByText('Trends (topical, new each year): 2 questions this year')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Trends for FY 2026' })).toBeInTheDocument();
    const currentTrends = screen.getByRole('group', { name: 'FY 2026 Trends questions (2)' });
    expect(currentTrends).not.toHaveAttribute('open');
    await user.click(within(currentTrends).getByText('FY 2026 Trends questions (2)'));
    expect(within(currentTrends).getByText(/T1/)).toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'FY 2025 Trends questions (1)' })).toBeInTheDocument();
    expect(screen.getByLabelText('Survey change counts')).toHaveTextContent(/; 2 still need a decision$/);
    expect(within(currentTrends).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Publish year at the bottom of this review/ })).toHaveAttribute('href', '#acrl-publish');
    expect(screen.getByRole('region', { name: 'New survey questions' })).toHaveAttribute('id', 'acrl-group-new');
    expect(screen.getByRole('region', { name: 'Reworded survey questions' })).not.toHaveTextContent('Trends question 5');
    const groupOrder = Array.from(screen.getByRole('region', { name: 'Annual question review' }).querySelectorAll('section[id^="acrl-group-"]')).map((section) => section.id);
    expect(groupOrder).toEqual(['acrl-group-new', 'acrl-group-removed', 'acrl-group-renumbered', 'acrl-group-reworded', 'acrl-group-unchanged']);
  });

  it('shows an explicit no-new-questions message and keeps all-decided groups collapsed', () => {
    const statuses: AcrlReviewResponse['items'][number]['status'][] = ['removed', 'renumbered', 'reworded', 'unchanged'];
    const items = statuses.map((status, index) => ({
      status,
      matchKind: status === 'removed' ? null : 'same' as const,
      score: 1,
      previous: status === 'new' ? null : makeQuestion('survey', String(index + 1)),
      current: status === 'removed' ? null : makeQuestion('survey', String(index + 10)),
      topical: false,
      decision: status === 'removed' ? null : 'approved' as const,
      linkId: null,
    }));
    const review = { ...makeReview(), items, unresolvedCount: 0, canPublish: true };
    render(<AcrlReviewPanel review={review} saving={false} publishing={false} onSave={vi.fn()} onPublish={vi.fn()} />);

    expect(screen.getByText('No new Survey questions this year.')).toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Renumbered questions · All 1 approved' })).not.toHaveAttribute('open');
    expect(screen.getByRole('group', { name: 'Reworded questions · All 1 approved' })).not.toHaveAttribute('open');
    expect(screen.getByRole('group', { name: 'Unchanged questions · All 1 approved' })).not.toHaveAttribute('open');
    expect(screen.getByRole('group', { name: 'New questions' })).toHaveAttribute('open');
  });

  it('shows the active year review and the ready-to-publish summary', async () => {
    listYears.mockResolvedValue([makeYear(2026)]);
    getReview.mockResolvedValue(makeReview());
    const user = userEvent.setup();
    renderAcrl();

    await user.click(await screen.findByRole('button', { name: 'Open review' }));

    expect(await screen.findByText('Ready to publish.')).toBeInTheDocument();
    expect(screen.getByText('Review open')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Publish year at the bottom of this review/ })).toHaveAttribute('href', '#acrl-publish');
  });

  it('shows assignment copy results after publishing a year', async () => {
    listYears.mockResolvedValue([makeYear(2026)]);
    getReview.mockResolvedValue(makeReview());
    publishYear.mockResolvedValue({
      ...makeReview(),
      status: 'published',
      assignmentCopy: {
        surveyYear: 2026,
        sourceYear: 2025,
        copiedSections: ['Circulation'],
        skippedSections: [],
        unmatchedSections: ['Old services'],
        alreadyAssigned: false,
        failed: false,
      },
    });
    const user = userEvent.setup();
    renderAcrl();

    await user.click(await screen.findByRole('button', { name: 'Open review' }));
    await user.click(await screen.findByRole('button', { name: 'Publish year' }));

    expect(await screen.findByText('Assignments copied from FY 2025 for: Circulation.')).toBeInTheDocument();
    expect(screen.getByText('No matching section: Old services.')).toBeInTheDocument();
  });

  it('reports assignment copy failure without hiding a successful publish', async () => {
    listYears.mockResolvedValue([makeYear(2026)]);
    getReview.mockResolvedValue(makeReview());
    publishYear.mockResolvedValue({
      ...makeReview(),
      status: 'published',
      assignmentCopy: {
        surveyYear: 2026,
        sourceYear: 2025,
        copiedSections: [],
        skippedSections: [],
        unmatchedSections: [],
        alreadyAssigned: false,
        failed: true,
      },
    });
    const user = userEvent.setup();
    renderAcrl();

    await user.click(await screen.findByRole('button', { name: 'Open review' }));
    await user.click(await screen.findByRole('button', { name: 'Publish year' }));

    expect(await screen.findByText('FY 2026 was published, but assignments could not be copied from FY 2025.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Published' })).toBeDisabled();
  });
});
