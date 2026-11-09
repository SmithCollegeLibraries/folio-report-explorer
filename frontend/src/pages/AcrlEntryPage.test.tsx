import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom';
import AcrlEntryPage from './AcrlEntryPage';
import AcrlSectionAssignmentsCard from '../components/AcrlSectionAssignmentsCard';
import type { AcrlEntryQuestion, AcrlEntryResponse } from '../types/acrl';

const { getEntry, saveAnswer, runAutofill, getAutofillMethods, setStatus, acceptSuggestions, getAssignments, getInvites, createInvite, resendInvite, revokeInvite, searchUsers, saveAssignments, saveQuestionAssignments, copyAssignments, getQuestionDetails, addQuestionNote, editQuestionNote, deleteQuestionNote, downloadCsv, auth } = vi.hoisted(() => ({
  getEntry: vi.fn(),
  saveAnswer: vi.fn(),
  runAutofill: vi.fn(),
  getAutofillMethods: vi.fn().mockResolvedValue({ methods: [] }),
  setStatus: vi.fn(),
  acceptSuggestions: vi.fn(),
  getAssignments: vi.fn().mockResolvedValue({ surveyYear: 2026, sections: [], questions: [] }),
  getInvites: vi.fn().mockResolvedValue({ invites: [], pendingCount: 0 }),
  createInvite: vi.fn(),
  resendInvite: vi.fn(),
  revokeInvite: vi.fn(),
  searchUsers: vi.fn().mockResolvedValue({ users: [] }),
  saveAssignments: vi.fn(),
  saveQuestionAssignments: vi.fn(),
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
  getAcrlInvites: getInvites,
  createAcrlInvite: createInvite,
  resendAcrlInvite: resendInvite,
  revokeAcrlInvite: revokeInvite,
  searchAcrlApprovedUsers: searchUsers,
  saveAcrlSectionAssignments: saveAssignments,
  saveAcrlQuestionAssignments: saveQuestionAssignments,
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
  const fixture = {
    sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', prompt: 'Physical circulation', answerType: 'number', required: 'yes',
    calculatedFrom: null, surveyOrder: 1,
    hasDetailsNotes: false,
    questionAssignees: [], assignees: [], assignedToCurrentUser: false, questionAssignedToCurrentUser: false,
    answer: { value_text: '20', value_number: '20', note: '', source: 'manual', source_label: null, updated_by: 'editor', updated_at: '2026-01-01 00:00:00.000001' },
    previous: { value: 10, label: '10', isSum: false, linkedValues: [{ questionId: '59', part: '', value: 10 }] },
    history: [],
    suggestion: null,
    comparison: { previousValue: 10, previousLabel: '10', changePercent: 100, highlighted: true, addNote: true },
    calculated: null,
    ...overrides,
  };
  return fixture as AcrlEntryQuestion;
}

function entry(status: 'open' | 'ready' = 'open'): AcrlEntryResponse {
  const fixture = {
    selectedYear: 2026, previousYear: 2025, years: [2026, 2025, 2024, 2023], thresholdPercent: 10,
    sections: [{ name: 'Circulation', status, setAt: null, reopenedAt: null, filled: 3, total: 8, assignees: [], assignedToCurrentUser: false, questionsAssignedToCurrentUser: 0, questions: [
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
    ] }, { name: 'Services', status: 'ready', setAt: null, reopenedAt: null, filled: 1, total: 2, assignees: [], assignedToCurrentUser: false, questionsAssignedToCurrentUser: 0, questions: [
      makeQuestion({ questionId: '70', part: '', section: 'Services', prompt: 'Second section item', comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false } }),
    ] }],
  };
  return fixture as AcrlEntryResponse;
}

function renderPage(options: { staleTime?: number; initialEntry?: string; basename?: string; assignmentHashLink?: boolean } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: options.staleTime } } });
  const initialEntry = options.initialEntry ?? '/acrl/entry/2026';
  return render(<QueryClientProvider client={client}><MemoryRouter basename={options.basename} initialEntries={[initialEntry]} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
    {options.assignmentHashLink && <Link to="/acrl/entry/2026#section-assignments">Go to section assignments</Link>}
    <Routes><Route path="/acrl/entry/:year" element={<AcrlEntryPage />} /></Routes>
  </MemoryRouter></QueryClientProvider>);
}

function renderAssignmentNavigation(initialEntry: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const year = {
    surveyYear: 2026,
    status: 'published' as const,
    sourceFileName: null,
    surveyPromptCount: 1,
    trendsPromptCount: 0,
    surveyItemCount: 1,
    trendsItemCount: 0,
    totalItemCount: 1,
    publishedAt: '2026-01-01',
  };
  return render(<QueryClientProvider client={client}><MemoryRouter basename="/fre" initialEntries={[`/fre${initialEntry}`]} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
    <div onClick={(event) => event.preventDefault()}>
      <Routes>
        <Route path="/acrl" element={<AcrlSectionAssignmentsCard years={[year]} />} />
        <Route path="/acrl/entry/:year" element={<AcrlEntryPage />} />
      </Routes>
    </div>
  </MemoryRouter></QueryClientProvider>);
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

    await screen.findByText('3 of 8 parts filled');
    const panel = screen.queryByRole('region', { name: 'Assignments' });
    const failures: string[] = [];
    if (panel?.getAttribute('id') !== 'section-assignments') failures.push('the renamed Assignments region keeps the section-assignments id');
    if (!panel || !scrolledElements.includes(panel)) failures.push('the assignment hash scrolls to the renamed Assignments region');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('scrolls to section assignments after navigating from the ACRL page', async () => {
    const scrolledElements: HTMLElement[] = [];
    scrollIntoViewDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value: function (this: HTMLElement) { scrolledElements.push(this); },
    });
    const user = userEvent.setup();
    auth.isAdmin = true;
    getEntry.mockResolvedValue(entry());
    renderAssignmentNavigation('/acrl');

    const yearSelect = await screen.findByLabelText('Published year for section assignments');
    await user.selectOptions(yearSelect, '2026');
    const failures: string[] = [];
    const check = (condition: boolean, message: string) => { if (!condition) failures.push(message); };
    const assignmentLink = screen.queryByRole('link', { name: 'Assign sections and questions for FY 2026' });
    check(assignmentLink?.getAttribute('href') === '/fre/acrl/entry/2026#section-assignments', 'the assignment link keeps the section panel anchor');
    check(assignmentLink?.getAttribute('href')?.startsWith('/fre/') === true, 'the assignment link includes the configured basename');
    if (assignmentLink) await user.click(assignmentLink);

    const panel = document.getElementById('section-assignments');
    check(panel !== null, 'the section assignment panel keeps its stable id');
    check(panel !== null && scrolledElements.includes(panel), 'navigation scrolls to the assignment panel');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('scrolls to section assignments when the hash is navigated to from the entry page itself', async () => {
    const scrolledElements: HTMLElement[] = [];
    scrollIntoViewDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value: function (this: HTMLElement) { scrolledElements.push(this); },
    });
    const user = userEvent.setup();
    auth.isAdmin = true;
    getEntry.mockResolvedValue(entry());
    renderPage({ basename: '/fre', initialEntry: '/fre/acrl/entry/2026', assignmentHashLink: true });

    await screen.findByText('3 of 8 parts filled');
    const panel = screen.queryByRole('region', { name: 'Assignments' });
    const link = screen.queryByRole('link', { name: 'Go to section assignments' });
    if (link) await user.click(link);
    const failures: string[] = [];
    if (!panel) failures.push('the renamed Assignments region is present for administrators');
    if (panel && !scrolledElements.includes(panel)) failures.push('the section assignment hash link scrolls to the renamed region');
    if (!link) failures.push('the existing Go to section assignments link remains available');
    if (failures.length) throw new Error(failures.join('; '));
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

    await screen.findByText('No sections assigned to you.');
    expect(screen.queryByRole('region', { name: 'Assignments' })).not.toBeInTheDocument();
    expect(scrolledElements).toHaveLength(0);
    expect(screen.queryByRole('region', { name: 'Your assignments' })).toBeInTheDocument();
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

  it('shows and saves cents for the Q20 currency suggestion in its answer row', async () => {
    const response = entry();
    response.sections[0].questions[0] = makeQuestion({
      questionId: '20', part: '', section: 'Expenditures', prompt: 'One-time expenditures', answerType: 'currency',
      answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    });
    getEntry.mockResolvedValue(response);
    getAutofillMethods.mockResolvedValue({ methods: [{
      sheet: 'survey', questionId: '20', part: '', queryId: 'acrl-20', queryVersion: 1, kind: 'dated',
      summary: [
        'Sums Smith payments minus credits in FOLIO fiscal year SCFY2025 on purchase-order lines whose order type is One-Time.',
        'Material type Admin (fees and services) is left out; payments with no purchase-order line and spending outside FOLIO are not included.',
        'This is FOLIO data only - adjust the value if other spending belongs here.',
      ],
      sql: 'select q20_one_time;',
    }] });
    runAutofill.mockResolvedValue({
      value: 1234.56, queryId: 'acrl-20', queryVersion: 1, ranAt: '2026-11-05T12:00:00-05:00',
      asOf: null, fyStart: '2024-07-01', fyEnd: '2025-07-01', kind: 'dated',
    });
    saveAnswer.mockResolvedValue({ conflict: false, answer: response.sections[0].questions[0].answer });
    renderPage();

    const row = (await screen.findByRole('heading', { name: 'One-time expenditures' })).closest('article') as HTMLElement;
    const fillButton = within(row).queryByRole('button', { name: 'Fill from FOLIO' });
    expect.soft(fillButton, 'Q20 currency answers should expose Fill from FOLIO in the answer row').toBeInTheDocument();
    if (fillButton) {
      await userEvent.click(fillButton);
      const suggestion = await within(row).findByRole('region', { name: 'FOLIO suggestion' });
      expect.soft(within(suggestion).queryByText('FOLIO count: 1,234.56')).toBeInTheDocument();
      expect.soft(within(suggestion).queryByText('Total: 1,234.56')).toBeInTheDocument();
      const method = await within(suggestion).findByRole('region', { name: 'How Fill from FOLIO counts this now' });
      expect.soft(method).toHaveTextContent('FOLIO data only - adjust the value if other spending belongs here.');

      await userEvent.click(within(suggestion).getByRole('button', { name: 'Use this value' }));
      await waitFor(() => expect(saveAnswer).toHaveBeenCalledTimes(1));
      expect.soft(saveAnswer).toHaveBeenCalledWith(2026, expect.objectContaining({
        sheet: 'survey', question_id: '20', part: '', value: '1234.56',
        note: expect.stringContaining('1,234.56'),
      }));
    }
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

  it('keeps Survey and Trends drafts separate for the same section question tuple', async () => {
    const response = entry();
    const surveyQuestion = makeQuestion({
      sheet: 'survey', questionId: '60', part: 'A', prompt: 'Survey shared tuple',
      answer: { value_text: '10', value_number: '10', note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    });
    const trendsQuestion = makeQuestion({
      sheet: 'trends', questionId: '60', part: 'A', prompt: 'Trends shared tuple',
      answer: { value_text: '20', value_number: '20', note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    });
    response.sections[0].questions = [surveyQuestion, trendsQuestion];
    getEntry.mockResolvedValue(response);

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/acrl/entry/2026']} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
      <Routes><Route path="/acrl/entry/:year" element={<AcrlEntryPage />} /></Routes>
    </MemoryRouter></QueryClientProvider>);

    await screen.findByRole('heading', { name: 'Survey shared tuple' });
    const user = userEvent.setup();
    const surveyRow = screen.getByRole('heading', { name: 'Survey shared tuple' }).closest('article') as HTMLElement;
    const trendsRow = screen.getByRole('heading', { name: 'Trends shared tuple' }).closest('article') as HTMLElement;
    const surveyInput = within(surveyRow).getByRole('spinbutton');
    const trendsInput = within(trendsRow).getByRole('spinbutton');
    await user.clear(surveyInput);
    await user.type(surveyInput, '111');
    await user.clear(trendsInput);
    await user.type(trendsInput, '222');

    const reorderedResponse = {
      ...response,
      sections: response.sections.map((section, index) => index === 0
        ? { ...section, questions: [trendsQuestion, surveyQuestion] }
        : section),
    };
    getEntry.mockResolvedValueOnce(reorderedResponse);
    await act(async () => {
      await client.invalidateQueries({ queryKey: ['acrl-entry', '2026'] });
    });
    await waitFor(() => expect([...document.querySelectorAll('article h3')].slice(0, 2).map((heading) => heading.textContent)).toEqual(['Trends shared tuple', 'Survey shared tuple']));

    const surveyInputAfterReorder = within(screen.getByRole('heading', { name: 'Survey shared tuple' }).closest('article') as HTMLElement).getByRole('spinbutton');
    const trendsInputAfterReorder = within(screen.getByRole('heading', { name: 'Trends shared tuple' }).closest('article') as HTMLElement).getByRole('spinbutton');
    const failures: string[] = [];
    if ((surveyInputAfterReorder as HTMLInputElement).value !== '111') failures.push('the Survey draft remains with its full question tuple after reorder');
    if ((trendsInputAfterReorder as HTMLInputElement).value !== '222') failures.push('the Trends draft remains with its full question tuple after reorder');
    if (failures.length) throw new Error(failures.join('; '));
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
    await user.click(within(row).getByRole('button', { name: 'Update answer' }));

    await waitFor(() => expect(saveAnswer).toHaveBeenCalledWith(2026, expect.objectContaining({ value: '{"value":"Lower"}' })));
  });

  it('shows assigned sections with progress and marks owned sections in navigation', async () => {
    const response = entry();
    response.sections[0].assignees = [{ id: 7, displayName: 'Alex Editor' }];
    response.sections[0].assignedToCurrentUser = true;
    getEntry.mockResolvedValue(response);
    renderPage();

    await screen.findByText('Assigned to: Alex Editor');
    const yourSections = screen.queryByRole('region', { name: 'Your assignments' });
    const failures: string[] = [];
    if (screen.queryByRole('heading', { name: 'Assignments' }) !== null) failures.push('the administrator Assignments panel stays hidden from editors');
    if (yourSections) {
      if (within(yourSections).queryByRole('link', { name: /Circulation.*3\/8.*Open/ }) === null) failures.push('the section link remains in Your assignments');
      if (within(yourSections).queryByRole('link', { name: /Services/ }) !== null) failures.push('unassigned sections stay out of Your assignments');
    } else failures.push('the navigation region is named Your assignments');
    if (screen.queryByText('Assigned to: Alex Editor') === null) failures.push('the existing section assignee card remains visible');
    const navigation = screen.queryByRole('navigation', { name: 'Survey sections' });
    if (!navigation || within(navigation).queryByRole('link', { name: /Circulation.*Your section/ }) === null) failures.push('the section tab keeps its Your section ownership marker');
    if (screen.queryByRole('region', { name: 'Your assignments' }) === null) failures.push('the navigation region is named Your assignments');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('shows an empty state when the signed-in user has no assigned sections', async () => {
    getEntry.mockResolvedValue(entry());
    renderPage();
    expect(await screen.findByText('No sections assigned to you.')).toBeInTheDocument();
  });

  it('links to individually assigned question cards and shows direct question ownership', async () => {
    const response = entry();
    const section = response.sections[0] as typeof response.sections[number] & { questionsAssignedToCurrentUser: number };
    const assignedQuestion = section.questions[0] as typeof section.questions[number] & {
      questionAssignees: Array<{ id: number; displayName: string }>;
      assignees: Array<{ id: number; displayName: string }>;
      assignedToCurrentUser: boolean;
      questionAssignedToCurrentUser: boolean;
    };
    section.questionsAssignedToCurrentUser = 1;
    assignedQuestion.questionAssignees = [{ id: 7, displayName: 'Alex Editor' }];
    assignedQuestion.assignees = [{ id: 7, displayName: 'Alex Editor' }];
    assignedQuestion.assignedToCurrentUser = true;
    assignedQuestion.questionAssignedToCurrentUser = true;
    getEntry.mockResolvedValue(response);
    renderPage();

    await screen.findByText('Physical circulation');
    const failures: string[] = [];
    const check = (condition: boolean, message: string) => { if (!condition) failures.push(message); };
    const questionLink = screen.queryByRole('link', { name: /Q60 · A/ });
    check(questionLink?.getAttribute('href') === '#acrl-q-survey-60-A', 'question navigation uses the stable full-tuple anchor');
    check(document.getElementById('acrl-q-survey-60-A') !== null, 'the question card exposes the stable full-tuple anchor');
    check(screen.queryByText('Assigned: Alex Editor') !== null, 'question card shows its direct assignee names');
    check(screen.queryByText('Yours') !== null, 'question card shows the direct ownership badge');
    check(screen.queryByRole('link', { name: /Your questions \(1\)/ }) !== null, 'section navigation shows the question ownership count');
    check(screen.queryByRole('region', { name: 'Your assignments' }) !== null, 'the navigation region is named Your assignments');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('marks saved answers as filled while preserving highlighted cards', async () => {
    const response = entry();
    response.sections[0].questions[2].answer.value_text = null;
    response.sections[0].questions[3].comparison.highlighted = false;
    response.sections[0].questions.push(makeQuestion({
      questionId: '66', prompt: 'Empty string answer', comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false },
      answer: { value_text: '', value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    }));
    getEntry.mockResolvedValue(response);
    renderPage();
    await screen.findByText('3 of 8 parts filled');

    const row = (prompt: string) => screen.getByRole('heading', { name: prompt }).closest('article') as HTMLElement;
    const highlightedRow = row('Physical circulation');
    expect(within(highlightedRow).getByText('Filled')).toBeInTheDocument();
    expect(highlightedRow).toHaveClass('border-amber-400', 'bg-amber-50');

    const calculatedRow = row('Calculated total');
    expect(within(calculatedRow).getByText('Filled')).toBeInTheDocument();
    expect(calculatedRow).toHaveClass('border-emerald-300', 'bg-emerald-50');

    for (const prompt of ['No prior answer', 'Empty string answer']) {
      const unfilledRow = row(prompt);
      expect(within(unfilledRow).queryByText('Filled')).not.toBeInTheDocument();
      expect(unfilledRow).toHaveClass('border-gray-200', 'bg-white');
    }
  });

  it('filters unanswered cards independently in open and ready sections', async () => {
    getEntry.mockResolvedValue(entry());
    renderPage();
    await screen.findByText('3 of 8 parts filled');

    const circulation = screen.getByRole('region', { name: 'Circulation section' });
    const services = screen.getByRole('region', { name: 'Services section' });
    const circulationToggle = within(circulation).getByRole('checkbox', { name: 'Show unanswered only' });
    const servicesToggle = within(services).getByRole('checkbox', { name: 'Show unanswered only' });
    expect(circulationToggle).not.toBeChecked();
    expect(servicesToggle).not.toBeChecked();
    expect(within(circulation).getByRole('heading', { name: 'New count' })).toBeInTheDocument();
    expect(within(services).getByRole('heading', { name: 'Second section item' })).toBeInTheDocument();

    await userEvent.click(circulationToggle);
    expect(within(circulation).queryByRole('heading', { name: 'New count' })).not.toBeInTheDocument();
    expect(within(circulation).getByRole('heading', { name: 'No prior answer' })).toBeInTheDocument();
    expect(within(circulation).getByText('3 of 8 parts filled')).toBeInTheDocument();
    expect(within(services).getByRole('heading', { name: 'Second section item' })).toBeInTheDocument();

    await userEvent.click(servicesToggle);
    expect(within(services).queryByRole('heading', { name: 'Second section item' })).not.toBeInTheDocument();
    expect(within(services).getByText('All parts in this section are filled.')).toBeInTheDocument();
    expect(within(services).getByText('1 of 2 parts filled')).toBeInTheDocument();
  });

  it('shows progress, approved prior values, advisory change, labels, and calculated mismatch', async () => {
    const initial = entry();
    const afterFirstSave = entry();
    afterFirstSave.sections[0].questions[0].answer = { ...initial.sections[0].questions[0].answer, value_text: null, value_number: null, updated_at: '2026-01-02' };
    const afterSecondSave = entry();
    afterSecondSave.sections[0].questions[0].answer = afterFirstSave.sections[0].questions[0].answer;
    afterSecondSave.sections[0].questions[2].answer = { ...initial.sections[0].questions[2].answer, value_text: 'yes', updated_at: '2026-01-03' };
    getEntry.mockResolvedValueOnce(initial).mockResolvedValueOnce(afterFirstSave).mockResolvedValueOnce(afterSecondSave);
    let resolveSave: ((value: unknown) => void) | undefined;
    saveAnswer.mockImplementation(() => new Promise((resolve) => { resolveSave = resolve; }));
    setStatus.mockResolvedValue({ section: 'Circulation', status: 'ready' });
    renderPage();

    expect(await screen.findByText('3 of 8 parts filled')).toBeInTheDocument();
    expect(screen.getByText('Approved linked values summed')).toBeInTheDocument();
    expect(screen.getAllByLabelText('Change 100%')).toHaveLength(2);
    expect(screen.getAllByText(/Add a note explaining the change/)).toHaveLength(2);
    expect(screen.getByText('Previous value: New (was 0)')).toBeInTheDocument();
    expect(screen.getByText('Previous value: No previous value')).toBeInTheDocument();
    expect(screen.getByText(/Entered value differs \(advisory\)/)).toBeInTheDocument();

    const filledRow = screen.getByRole('heading', { name: 'Physical circulation' }).closest('article') as HTMLElement;
    const unfilledRow = screen.getByRole('heading', { name: 'No prior answer' }).closest('article') as HTMLElement;
    expect(within(filledRow).getByRole('button', { name: 'Update answer' })).toBeInTheDocument();
    expect(within(unfilledRow).getByRole('button', { name: 'Save answer' })).toBeInTheDocument();

    await userEvent.clear(within(filledRow).getByRole('spinbutton'));
    expect(within(filledRow).getByRole('button', { name: 'Update answer' })).toBeInTheDocument();
    await userEvent.click(within(filledRow).getByRole('button', { name: 'Update answer' }));
    expect(within(filledRow).getByRole('button', { name: 'Updating…' })).toBeDisabled();
    await act(async () => resolveSave?.({ conflict: false, answer: afterFirstSave.sections[0].questions[0].answer }));
    await waitFor(() => expect(within(filledRow).getByRole('button', { name: 'Save answer' })).toBeInTheDocument());

    await userEvent.selectOptions(within(unfilledRow).getByRole('combobox', { name: 'Answer' }), 'yes');
    expect(within(unfilledRow).getByRole('button', { name: 'Save answer' })).toBeInTheDocument();
    await userEvent.click(within(unfilledRow).getByRole('button', { name: 'Save answer' }));
    expect(within(unfilledRow).getByRole('button', { name: 'Saving…' })).toBeDisabled();
    await act(async () => resolveSave?.({ conflict: false, answer: afterSecondSave.sections[0].questions[2].answer }));
    await waitFor(() => expect(within(unfilledRow).getByRole('button', { name: 'Update answer' })).toBeInTheDocument());

    expect(saveAnswer).toHaveBeenNthCalledWith(1, 2026, expect.objectContaining({ sheet: 'survey', question_id: '60', part: 'A', value: '', expected_updated_at: '2026-01-01 00:00:00.000001' }));
    expect(saveAnswer).toHaveBeenNthCalledWith(2, 2026, expect.objectContaining({ sheet: 'survey', question_id: '62', part: '', value: 'yes', expected_updated_at: null }));
  });

  it('shows the current value after an optimistic-lock conflict', async () => {
    getEntry.mockResolvedValue(entry());
    saveAnswer.mockRejectedValue({ response: { status: 409, data: { current: { value_text: '25' } } } });
    renderPage();
    await screen.findByText('3 of 8 parts filled');
    await userEvent.click(screen.getAllByRole('button', { name: 'Update answer' })[0]);
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
    expect(within(calculatedRow as HTMLElement).queryByRole('button', { name: 'Update answer' })).not.toBeInTheDocument();
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
    expect(screen.getAllByRole('button', { name: 'Reopen section' })[0]).toBeEnabled();
    expect(screen.getAllByRole('spinbutton')[0]).toBeEnabled();
    expect(screen.queryByRole('heading', { name: 'Assignments' })).toBeInTheDocument();
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
