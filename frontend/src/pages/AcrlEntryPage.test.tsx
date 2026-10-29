import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import AcrlEntryPage from './AcrlEntryPage';
import type { AcrlEntryQuestion, AcrlEntryResponse } from '../types/acrl';

const { getEntry, saveAnswer, runAutofill, getAutofillMethods, setStatus, acceptSuggestions, getAssignments, searchUsers, saveAssignments, copyAssignments, getQuestionDetails, addQuestionNote, editQuestionNote, deleteQuestionNote, downloadCsv, auth } = vi.hoisted(() => ({
  getEntry: vi.fn(),
  saveAnswer: vi.fn(),
  runAutofill: vi.fn(),
  getAutofillMethods: vi.fn().mockResolvedValue({ methods: [] }),
  setStatus: vi.fn(),
  acceptSuggestions: vi.fn(),
  getAssignments: vi.fn().mockResolvedValue({ surveyYear: 2026, sections: [] }),
  searchUsers: vi.fn().mockResolvedValue({ users: [] }),
  saveAssignments: vi.fn(),
  copyAssignments: vi.fn(),
  getQuestionDetails: vi.fn(),
  addQuestionNote: vi.fn(),
  editQuestionNote: vi.fn(),
  deleteQuestionNote: vi.fn(),
  downloadCsv: vi.fn(),
  auth: { isAdmin: false },
}));
let scrollIntoViewDescriptor: PropertyDescriptor | undefined;

vi.mock('../api/acrl', () => ({
  getAcrlEntry: getEntry,
  saveAcrlAnswer: saveAnswer,
  runAcrlAutofill: runAutofill,
  getAcrlAutofillMethods: getAutofillMethods,
  setAcrlSectionStatus: setStatus,
  acceptAcrlSuggestions: acceptSuggestions,
  getAcrlSectionAssignments: getAssignments,
  searchAcrlApprovedUsers: searchUsers,
  saveAcrlSectionAssignments: saveAssignments,
  copyAcrlAssignmentsFromPrevious: copyAssignments,
  getAcrlQuestionDetails: getQuestionDetails,
  addAcrlQuestionNote: addQuestionNote,
  editAcrlQuestionNote: editQuestionNote,
  deleteAcrlQuestionNote: deleteQuestionNote,
}));
vi.mock('../utils/csv', () => ({ downloadCsv }));
vi.mock('../hooks/useAuth', () => ({ useAuth: () => auth }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  auth.isAdmin = false;
  if (scrollIntoViewDescriptor) Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', scrollIntoViewDescriptor);
  else Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView');
  scrollIntoViewDescriptor = undefined;
});

function makeQuestion(overrides: Partial<AcrlEntryQuestion> = {}): AcrlEntryQuestion {
  return {
    sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', prompt: 'Physical circulation', answerType: 'number', required: 'yes',
    calculatedFrom: null, surveyOrder: 1,
    hasDetailsNotes: false,
    answer: { value_text: '20', value_number: '20', note: '', source: 'manual', source_label: null, updated_by: 'editor', updated_at: '2026-01-01 00:00:00.000001' },
    previous: { value: 10, label: '10', isSum: false, linkedValues: [{ questionId: '59', part: '', value: 10 }] },
    history: [],
    suggestion: null,
    comparison: { previousValue: 10, previousLabel: '10', changePercent: 100, highlighted: true, addNote: true },
    calculated: null,
    ...overrides,
  };
}

function entry(status: 'open' | 'ready' = 'open'): AcrlEntryResponse {
  return {
    selectedYear: 2026, previousYear: 2025, years: [2026, 2025, 2024, 2023], thresholdPercent: 10,
    sections: [{ name: 'Circulation', status, setAt: null, reopenedAt: null, filled: 3, total: 8, assignees: [], assignedToCurrentUser: false, questions: [
      makeQuestion({
        previous: { value: 10, label: 'Sum of linked values: 10', isSum: true, linkedValues: [{ questionId: '59', part: '', value: 10 }] },
        history: [
          { year: 2025, value: 10, label: 'Sum of linked values: 10', isSum: true, linkedValues: [{ questionId: '59', part: '', value: 10, note: 'FY2025 note' }], notes: [{ questionId: '59', part: '', note: 'FY2025 note' }] },
          { year: 2024, value: 8, label: '8', isSum: false, linkedValues: [{ questionId: '59', part: '', value: 8, note: null }], notes: [] },
          { year: 2023, value: 6, label: '6', isSum: false, linkedValues: [{ questionId: '59', part: '', value: 6, note: null }], notes: [] },
        ],
      }),
      makeQuestion({ questionId: '61', part: '', prompt: 'New count', answer: { value_text: '25', value_number: '25', note: null, source: null, source_label: null, updated_by: null, updated_at: null }, previous: { value: 0, label: 'New (was 0)', isSum: false, linkedValues: [] }, comparison: { previousValue: 0, previousLabel: 'New (was 0)', changePercent: null, highlighted: false, addNote: false } }),
      makeQuestion({ questionId: '62', part: '', prompt: 'No prior answer', answerType: 'yes/no', answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null }, previous: { value: null, label: 'No previous value', isSum: false, linkedValues: [] }, comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false } }),
      makeQuestion({ questionId: '63', part: '', prompt: 'Calculated total', calculatedFrom: 'sum of 1A, 2A', calculated: { supported: true, raw: null, value: 10, mismatch: true, waitingFor: [] } }),
      makeQuestion({ questionId: '0A', part: '', prompt: 'Yes or no answer', answerType: 'yes/no', answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null }, previous: { value: 'no', label: 'No', isSum: false, linkedValues: [{ questionId: '0A', part: '', value: 'no' }] }, history: [
        { year: 2025, value: 'no', label: 'No', isSum: false, linkedValues: [{ questionId: '0A', part: '', value: 'no', note: null }], notes: [] },
        { year: 2024, value: 'yes', label: 'Yes', isSum: false, linkedValues: [{ questionId: '0A', part: '', value: 'yes', note: null }], notes: [] },
        { year: 2023, value: 'no', label: 'No', isSum: false, linkedValues: [{ questionId: '0A', part: '', value: 'no', note: null }], notes: [] },
      ], suggestion: { value: 'no', label: 'No', year: 2025 }, comparison: { previousValue: 'no', previousLabel: 'No', changePercent: null, highlighted: false, addNote: false } }),
      makeQuestion({ questionId: '0B', part: '', prompt: 'Choice answer', answerType: 'choice', answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null }, previous: { value: 'Option A', label: 'Option A', isSum: false, linkedValues: [{ questionId: '0B', part: '', value: 'Option A' }] }, history: [
        { year: 2025, value: 'Option A', label: 'Option A', isSum: false, linkedValues: [{ questionId: '0B', part: '', value: 'Option A', note: null }], notes: [] },
        { year: 2024, value: 'Option B', label: 'Option B', isSum: false, linkedValues: [{ questionId: '0B', part: '', value: 'Option B', note: null }], notes: [] },
        { year: 2023, value: 'Option C', label: 'Option C', isSum: false, linkedValues: [{ questionId: '0B', part: '', value: 'Option C', note: null }], notes: [] },
      ], suggestion: { value: 'Option A', label: 'Option A', year: 2025 }, comparison: { previousValue: 'Option A', previousLabel: 'Option A', changePercent: null, highlighted: false, addNote: false } }),
      makeQuestion({ questionId: '64', part: '', prompt: 'Answered yes/no', answerType: 'yes/no', answer: { value_text: 'yes', value_number: null, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: '2026-01-02' }, suggestion: null, comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false } }),
      makeQuestion({ questionId: '65', part: '', prompt: 'Numeric suggestion excluded', answerType: 'number', answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null }, suggestion: null, comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false } }),
    ] }, { name: 'Services', status: 'ready', setAt: null, reopenedAt: null, filled: 1, total: 2, assignees: [], assignedToCurrentUser: false, questions: [
      makeQuestion({ questionId: '70', part: '', section: 'Services', prompt: 'Second section item', comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false } }),
    ] }],
  };
}

function renderPage(options: { staleTime?: number; initialEntry?: string } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: options.staleTime } } });
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[options.initialEntry ?? '/acrl/entry/2026']}><Routes><Route path="/acrl/entry/:year" element={<AcrlEntryPage />} /></Routes></MemoryRouter></QueryClientProvider>);
}

describe('ACRL entry page', () => {
  it('scrolls to section assignments after entry loads when the hash targets the panel', async () => {
    const scrolledElements: HTMLElement[] = [];
    scrollIntoViewDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value: function (this: HTMLElement) { scrolledElements.push(this); },
    });
    auth.isAdmin = true;
    getEntry.mockResolvedValue(entry());
    renderPage({ initialEntry: '/acrl/entry/2026#section-assignments' });

    const panel = await screen.findByRole('region', { name: 'Section assignments' });
    expect(panel).toHaveAttribute('id', 'section-assignments');
    await waitFor(() => expect(scrolledElements).toContain(panel));
  });

  it('does not try to scroll to an assignment panel for non-admins', async () => {
    const scrolledElements: HTMLElement[] = [];
    scrollIntoViewDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value: function (this: HTMLElement) { scrolledElements.push(this); },
    });
    getEntry.mockResolvedValue(entry());
    renderPage({ initialEntry: '/acrl/entry/2026#section-assignments' });

    await screen.findByRole('region', { name: 'Your sections' });

    expect(screen.queryByRole('region', { name: 'Section assignments' })).not.toBeInTheDocument();
    expect(scrolledElements).toHaveLength(0);
  });

  it('saves an accepted FOLIO suggestion through the normal answer API with query provenance', async () => {
    const response = entry();
    response.sections[0].questions[0] = makeQuestion({
      questionId: '60', part: 'A', prompt: 'Total Physical Circulation',
      answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    });
    getEntry.mockResolvedValue(response);
    runAutofill.mockResolvedValue({
      value: 18438, queryId: 'acrl-60', queryVersion: 1, ranAt: '2026-10-28T12:34:56-04:00',
      asOf: null, fyStart: '2025-07-01', fyEnd: '2026-07-01', kind: 'dated',
    });
    saveAnswer.mockResolvedValue({ conflict: false, answer: response.sections[0].questions[0].answer });
    renderPage();

    const row = (await screen.findByRole('heading', { name: 'Total Physical Circulation' })).closest('article') as HTMLElement;
    await userEvent.click(within(row).getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await within(row).findByText('Total: 18,438')).toBeInTheDocument();
    await userEvent.click(within(row).getByRole('button', { name: 'Use this value' }));
    await waitFor(() => expect(saveAnswer).toHaveBeenCalledWith(2026, expect.objectContaining({
      sheet: 'survey', question_id: '60', part: 'A', value: '18438',
      note: 'FOLIO physical circulation 18,438 (query acrl-60 v1, FY2026, run 2026-10-28)',
    })));
  });

  it('clears an autofill suggestion when returning to another cached survey year', async () => {
    const currentYear = entry();
    const previousYear = entry();
    previousYear.selectedYear = 2025;
    previousYear.previousYear = 2024;
    for (const response of [currentYear, previousYear]) {
      response.sections[0].questions[0] = makeQuestion({
        questionId: '60', part: 'A', prompt: 'Total Physical Circulation',
        answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
      });
    }
    getEntry.mockResolvedValueOnce(currentYear).mockResolvedValueOnce(previousYear);
    runAutofill.mockResolvedValue({
      value: 18438, queryId: 'acrl-60', queryVersion: 1, ranAt: '2026-10-28T12:34:56-04:00',
      asOf: null, fyStart: '2024-07-01', fyEnd: '2025-07-01', kind: 'dated',
    });
    renderPage({ staleTime: Infinity });

    await screen.findByRole('heading', { name: 'Total Physical Circulation' });
    await userEvent.selectOptions(screen.getByLabelText('Survey year'), '2025');
    const previousRow = (await screen.findByRole('heading', { name: 'Total Physical Circulation' })).closest('article') as HTMLElement;
    await userEvent.click(within(previousRow).getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await within(previousRow).findByText('Total: 18,438')).toBeInTheDocument();

    await userEvent.selectOptions(await screen.findByLabelText('Survey year'), '2026');
    await waitFor(() => expect(screen.getByLabelText('Survey year')).toHaveValue('2026'));
    expect(screen.queryByRole('region', { name: 'FOLIO suggestion' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Fill from FOLIO' })).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Use this value' })).not.toBeInTheDocument();
    expect(saveAnswer).not.toHaveBeenCalled();
  });

  it('refetches the displayed survey year before exporting the refreshed answers', async () => {
    const initial = entry();
    const refreshed = entry();
    refreshed.sections[0].questions[0].answer.value_text = '999';
    refreshed.sections[0].questions[0].answer.value_number = '999';
    let resolveRefresh: (value: AcrlEntryResponse) => void = () => undefined;
    getEntry.mockResolvedValueOnce(initial);
    const user = userEvent.setup();
    renderPage();

    const exportButton = await screen.findByRole('button', { name: 'Export CSV' });
    getEntry.mockReturnValueOnce(new Promise((resolve) => { resolveRefresh = resolve; }));
    await user.click(exportButton);
    await waitFor(() => expect(getEntry).toHaveBeenCalledTimes(2));
    expect(getEntry).toHaveBeenLastCalledWith(2026);
    expect(exportButton).toBeDisabled();
    expect(exportButton).toHaveTextContent('Exporting…');

    resolveRefresh(refreshed);
    await waitFor(() => expect(downloadCsv).toHaveBeenCalledWith(
      expect.any(Array),
      expect.arrayContaining([expect.objectContaining({ question_id: '60', value: '999' })]),
      'acrl-fy2026-benchmark',
      { bom: true },
    ));
    expect(exportButton).toBeEnabled();
  });

  it('renders stored rating options as a dropdown and saves the selected answer as JSON text', async () => {
    const response = entry();
    response.sections[0].questions.push(makeQuestion({
      sheet: 'trends', questionId: 'T1', part: '', prompt: 'Rating question', answerType: 'choice',
      answerOptions: { kind: 'rating', rows: [], choices: ['Higher', 'Same', 'Lower'], allowOther: false },
      answer: { value_text: '{"value":"Higher"}', value_number: null, note: null, source: 'manual', source_label: null, updated_by: 'editor', updated_at: '2026-01-03' },
      answerDisplay: 'Higher',
    }));
    getEntry.mockResolvedValue(response);
    saveAnswer.mockResolvedValue({ conflict: false, answer: response.sections[0].questions[0].answer });
    const user = userEvent.setup();
    renderPage();

    const row = (await screen.findByRole('heading', { name: 'Rating question' })).closest('article') as HTMLElement;
    const answerSelect = within(row).getByRole('combobox', { name: 'Rating question' });
    expect(answerSelect).toHaveValue('Higher');
    expect(within(row).getByText('Current value: Higher')).toBeInTheDocument();
    await user.selectOptions(answerSelect, 'Lower');
    await user.click(within(row).getByRole('button', { name: 'Save answer' }));

    await waitFor(() => expect(saveAnswer).toHaveBeenCalledWith(2026, expect.objectContaining({ value: '{"value":"Lower"}' })));
  });

  it('shows assigned sections with progress and marks owned sections in navigation', async () => {
    const response = entry();
    response.sections[0].assignees = [{ id: 7, displayName: 'Alex Editor' }];
    response.sections[0].assignedToCurrentUser = true;
    getEntry.mockResolvedValue(response);
    renderPage();

    const yourSections = await screen.findByRole('region', { name: 'Your sections' });
    expect(screen.queryByRole('heading', { name: 'Section assignments' })).not.toBeInTheDocument();
    expect(within(yourSections).getByRole('link', { name: /Circulation.*3\/8.*Open/ })).toBeInTheDocument();
    expect(within(yourSections).queryByRole('link', { name: /Services/ })).not.toBeInTheDocument();
    expect(screen.getByText('Assigned to: Alex Editor')).toBeInTheDocument();
    expect(within(screen.getByRole('navigation', { name: 'Survey sections' })).getByRole('link', { name: /Circulation.*Your section/ })).toBeInTheDocument();
  });

  it('shows an empty state when the signed-in user has no assigned sections', async () => {
    getEntry.mockResolvedValue(entry());
    renderPage();
    expect(await screen.findByText('No sections assigned to you.')).toBeInTheDocument();
  });

  it('shows progress, approved prior values, advisory change, labels, and calculated mismatch', async () => {
    getEntry.mockResolvedValue(entry());
    saveAnswer.mockResolvedValue({ conflict: false, answer: entry().sections[0].questions[0].answer });
    setStatus.mockResolvedValue({ section: 'Circulation', status: 'ready' });
    renderPage();

    expect(await screen.findByText('3 of 8 parts filled')).toBeInTheDocument();
    expect(screen.getByText('Approved linked values summed')).toBeInTheDocument();
    expect(screen.getAllByLabelText('Change 100%')).toHaveLength(2);
    expect(screen.getAllByText(/Add a note explaining the change/)).toHaveLength(2);
    expect(screen.getByText('Previous value: New (was 0)')).toBeInTheDocument();
    expect(screen.getByText('Previous value: No previous value')).toBeInTheDocument();
    expect(screen.getByText(/Entered value differs \(advisory\)/)).toBeInTheDocument();

    await userEvent.click(screen.getAllByRole('button', { name: 'Save answer' })[0]);
    await waitFor(() => expect(saveAnswer).toHaveBeenCalledWith(2026, expect.objectContaining({ sheet: 'survey', question_id: '60', part: 'A', expected_updated_at: '2026-01-01 00:00:00.000001' })));
  });

  it('shows the current value after an optimistic-lock conflict', async () => {
    getEntry.mockResolvedValue(entry());
    saveAnswer.mockRejectedValue({ response: { status: 409, data: { current: { value_text: '25' } } } });
    renderPage();
    await screen.findByText('3 of 8 parts filled');
    await userEvent.click(screen.getAllByRole('button', { name: 'Save answer' })[0]);
    expect(await screen.findByRole('alert')).toHaveTextContent('Current value: 25');
  });

  it('shows calculated totals and dependencies without answer controls', async () => {
    const response = entry();
    response.sections[0].questions[3].answer = { value_text: null, value_number: null, note: null, source: 'calculated', source_label: null, updated_by: null, updated_at: null };
    response.sections[0].questions[3].calculated = { supported: true, raw: null, value: null, mismatch: false, waitingFor: ['2A'] };
    getEntry.mockResolvedValue(response);
    renderPage();
    await screen.findByText('3 of 8 parts filled');
    const calculatedRow = screen.getByText('Calculated total').closest('article');
    expect(calculatedRow).not.toBeNull();
    expect(within(calculatedRow as HTMLElement).getByText('Calculated from 1A + 2A')).toBeInTheDocument();
    expect(within(calculatedRow as HTMLElement).getByText(/Waiting for 2A/)).toBeInTheDocument();
    expect(within(calculatedRow as HTMLElement).queryByLabelText('Answer')).not.toBeInTheDocument();
    expect(within(calculatedRow as HTMLElement).queryByRole('button', { name: 'Save answer' })).not.toBeInTheDocument();
  });

  it('locks ready sections for editors and exposes reopen to administrators', async () => {
    getEntry.mockResolvedValue(entry('ready'));
    renderPage();
    expect(await screen.findAllByText('This section is ready for review and is locked for editing.')).toHaveLength(2);
    expect(screen.getAllByRole('spinbutton')[0]).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Reopen section' })).not.toBeInTheDocument();

    cleanup();
    auth.isAdmin = true;
    getEntry.mockResolvedValue(entry('ready'));
    renderPage();
    expect(await screen.findAllByRole('button', { name: 'Reopen section' })).toHaveLength(2);
    expect(screen.getByRole('heading', { name: 'Section assignments' })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Reopen section' })[0]).toBeEnabled();
    expect(screen.getAllByRole('spinbutton')[0]).toBeEnabled();
  });

  it('shows three prior years and expands historical notes', async () => {
    getEntry.mockResolvedValue(entry());
    renderPage();
    await screen.findByText('3 of 8 parts filled');

    const row = screen.getByText('Physical circulation').closest('article') as HTMLElement;
    expect(within(row).getByText('FY2025: Sum of linked values: 10')).toBeInTheDocument();
    expect(within(row).getByText('FY2024: 8')).toBeInTheDocument();
    expect(within(row).getByText('FY2023: 6')).toBeInTheDocument();
    await userEvent.click(within(row).getByText('Prior-year notes'));
    expect(within(row).getByText('FY2025 · Q59: FY2025 note')).toBeInTheDocument();
  });

  it('accepts a single yes/no suggestion as a manual answer', async () => {
    getEntry.mockResolvedValue(entry());
    acceptSuggestions.mockResolvedValue({ accepted: 1 });
    renderPage();
    await screen.findByText('3 of 8 parts filled');

    const row = screen.getByText('Yes or no answer').closest('article') as HTMLElement;
    expect(within(row).getByText('Last year: No')).toBeInTheDocument();
    await userEvent.click(within(row).getByRole('button', { name: 'Accept' }));
    expect(acceptSuggestions).toHaveBeenCalledTimes(1);
    expect(acceptSuggestions).toHaveBeenCalledWith(2026, 'Circulation', [{ sheet: 'survey', question_id: '0A', part: '' }]);
    expect(within(row).getByLabelText('Answer')).toHaveValue('');
  });

  it('accepts all yes/no and choice suggestions in one section save', async () => {
    getEntry.mockResolvedValue(entry());
    acceptSuggestions.mockResolvedValue({ accepted: 2 });
    renderPage();
    await screen.findByText('3 of 8 parts filled');

    expect(screen.queryByText('Last year: 17')).not.toBeInTheDocument();
    expect(screen.queryByText('Last year: yes')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Accept all suggestions (2)' }));
    expect(acceptSuggestions).toHaveBeenCalledTimes(1);
    expect(acceptSuggestions).toHaveBeenCalledWith(2026, 'Circulation', [
      { sheet: 'survey', question_id: '0A', part: '' },
      { sheet: 'survey', question_id: '0B', part: '' },
    ]);
  });

  it('shows sticky section links with progress and ready state, keeps drafts, and tracks the section in view', async () => {
    getEntry.mockResolvedValue(entry());
    renderPage();
    await screen.findByText('3 of 8 parts filled');

    const navigation = screen.getByRole('navigation', { name: 'Survey sections' });
    expect(navigation).toHaveClass('sticky');
    expect(within(navigation).getByRole('link', { name: /Circulation.*3\/8.*Open/ })).toHaveAttribute('aria-current', 'location');
    expect(within(navigation).getByRole('link', { name: /Services.*1\/2.*Ready/ })).toBeInTheDocument();

    const firstInput = screen.getAllByRole('spinbutton')[0];
    await userEvent.clear(firstInput);
    await userEvent.type(firstInput, '777');
    await userEvent.click(within(navigation).getByRole('link', { name: /Services/ }));
    expect(firstInput).toHaveValue(777);

    const servicesSection = document.getElementById('acrl-section-2') as HTMLElement;
    const circulationSection = document.getElementById('acrl-section-1') as HTMLElement;
    vi.spyOn(servicesSection, 'getBoundingClientRect').mockReturnValue({ top: 80, bottom: 300, left: 0, right: 500, width: 500, height: 220, x: 0, y: 80, toJSON: () => ({}) });
    vi.spyOn(circulationSection, 'getBoundingClientRect').mockReturnValue({ top: -300, bottom: 20, left: 0, right: 500, width: 500, height: 320, x: 0, y: -300, toJSON: () => ({}) });
    window.dispatchEvent(new Event('scroll'));
    await waitFor(() => expect(within(navigation).getByRole('link', { name: /Services/ })).toHaveAttribute('aria-current', 'location'));
  });

  it('opens a labelled Details modal with instruction and collection-note tabs, then restores focus on Escape', async () => {
    getEntry.mockResolvedValue(entry());
    getQuestionDetails.mockResolvedValue({
      instructions: [
        { blockKey: 'question:60', heading: 'Q60. Physical circulation', text: 'Count checkouts from general and reserve collections.', kind: 'question' },
        { blockKey: 'definition:circulation', heading: 'Circulation', text: 'A checkout is recorded when material leaves the library.', kind: 'definition' },
      ],
      notes: [{ id: 8, note: 'Checked the circulation report.', source: '2025 worksheet', origin_year: 2025, created_at: '2026-01-02 10:00:00', edited_at: null }],
      canAddNote: true,
      canManageNotes: false,
    });
    renderPage();

    const trigger = (await screen.findAllByRole('button', { name: 'Details' }))[0];
    await userEvent.click(trigger);
    const dialog = await screen.findByRole('dialog', { name: 'Question details' });
    expect(document.activeElement).toBe(within(dialog).getByRole('button', { name: 'Close details' }));
    await userEvent.tab({ shift: true });
    expect(document.activeElement).toBe(within(dialog).getByRole('tab', { name: 'How we collected this' }));
    await userEvent.click(within(dialog).getByRole('tab', { name: 'Instructions' }));
    expect(within(dialog).getByRole('tab', { name: 'Instructions' })).toHaveAttribute('aria-selected', 'true');
    expect(within(dialog).getByText('Count checkouts from general and reserve collections.')).toBeInTheDocument();
    expect(getQuestionDetails).toHaveBeenCalledWith(2026, 'survey', '60', 'A');

    await userEvent.click(within(dialog).getByRole('tab', { name: 'How we collected this' }));
    expect(within(dialog).getByText('Checked the circulation report.')).toBeInTheDocument();
    expect(within(dialog).getByText('2025 worksheet · FY2025')).toBeInTheDocument();

    await userEvent.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Question details' })).not.toBeInTheDocument());
    expect(document.activeElement).toBe(trigger);
  });

  it('adds a signed-in collection note with the current year as its origin', async () => {
    getEntry.mockResolvedValue(entry());
    getQuestionDetails.mockResolvedValue({ instructions: [], notes: [], canAddNote: true, canManageNotes: false });
    addQuestionNote.mockResolvedValue({ id: 12, note: 'Compared the sampled items with holdings.', source: 'How I collected this', origin_year: 2026, created_at: '2026-10-27 13:00:00', edited_at: null });
    renderPage();

    await userEvent.click((await screen.findAllByRole('button', { name: 'Details' }))[0]);
    const dialog = await screen.findByRole('dialog', { name: 'Question details' });
    await userEvent.click(within(dialog).getByRole('tab', { name: 'How we collected this' }));
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Collection note' }), 'Compared the sampled items with holdings.');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add note' }));

    await waitFor(() => expect(addQuestionNote).toHaveBeenCalledWith(2026, {
      sheet: 'survey', question_id: '60', part: 'A', note: 'Compared the sampled items with holdings.', source: 'How I collected this', origin_year: 2026,
    }));
  });

  it('lets administrators edit and remove existing collection notes', async () => {
    auth.isAdmin = true;
    getEntry.mockResolvedValue(entry());
    getQuestionDetails.mockResolvedValue({
      instructions: [],
      notes: [{ id: 8, note: 'Existing collection method.', source: 'FY2025 notes', origin_year: 2025, created_at: null, edited_at: null }],
      canAddNote: true,
      canManageNotes: true,
    });
    editQuestionNote.mockResolvedValue({ id: 8, note: 'Updated collection method.', source: 'FY2024 notes', origin_year: 2024, created_at: null, edited_at: null });
    deleteQuestionNote.mockResolvedValue(undefined);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderPage();

    await userEvent.click((await screen.findAllByRole('button', { name: 'Details' }))[0]);
    const dialog = await screen.findByRole('dialog', { name: 'Question details' });
    await userEvent.click(within(dialog).getByRole('tab', { name: 'How we collected this' }));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Edit note' }));
    await userEvent.clear(within(dialog).getByRole('textbox', { name: 'Edit note' }));
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Edit note' }), 'Updated collection method.');
    await userEvent.clear(within(dialog).getByRole('textbox', { name: 'Note source' }));
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Note source' }), 'FY2024 notes');
    await userEvent.clear(within(dialog).getByRole('spinbutton', { name: 'Note year' }));
    await userEvent.type(within(dialog).getByRole('spinbutton', { name: 'Note year' }), '2024');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(editQuestionNote).toHaveBeenCalledWith(8, { note: 'Updated collection method.', source: 'FY2024 notes', origin_year: 2024 }));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Remove note' }));
    await waitFor(() => expect(deleteQuestionNote).toHaveBeenCalledWith(8));
    expect(confirm).toHaveBeenCalledWith('Remove this collection note?');
    confirm.mockRestore();
  });

  it('marks rows that already have collection notes', async () => {
    const response = entry();
    response.sections[0].questions[0].hasDetailsNotes = true;
    getEntry.mockResolvedValue(response);
    renderPage();

    expect(await screen.findAllByLabelText('Has collection notes')).toHaveLength(1);
  });
});
