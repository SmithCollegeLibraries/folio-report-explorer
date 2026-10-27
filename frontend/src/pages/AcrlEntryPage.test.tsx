import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import AcrlEntryPage from './AcrlEntryPage';
import type { AcrlEntryQuestion, AcrlEntryResponse } from '../types/acrl';

const { getEntry, saveAnswer, setStatus, acceptSuggestions, getAssignments, searchUsers, saveAssignments, copyAssignments, auth } = vi.hoisted(() => ({
  getEntry: vi.fn(),
  saveAnswer: vi.fn(),
  setStatus: vi.fn(),
  acceptSuggestions: vi.fn(),
  getAssignments: vi.fn().mockResolvedValue({ surveyYear: 2026, sections: [] }),
  searchUsers: vi.fn().mockResolvedValue({ users: [] }),
  saveAssignments: vi.fn(),
  copyAssignments: vi.fn(),
  auth: { isAdmin: false },
}));

vi.mock('../api/acrl', () => ({
  getAcrlEntry: getEntry,
  saveAcrlAnswer: saveAnswer,
  setAcrlSectionStatus: setStatus,
  acceptAcrlSuggestions: acceptSuggestions,
  getAcrlSectionAssignments: getAssignments,
  searchAcrlApprovedUsers: searchUsers,
  saveAcrlSectionAssignments: saveAssignments,
  copyAcrlAssignmentsFromPrevious: copyAssignments,
}));
vi.mock('../hooks/useAuth', () => ({ useAuth: () => auth }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  auth.isAdmin = false;
});

function makeQuestion(overrides: Partial<AcrlEntryQuestion> = {}): AcrlEntryQuestion {
  return {
    sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', prompt: 'Physical circulation', answerType: 'number', required: 'yes',
    calculatedFrom: null, surveyOrder: 1,
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

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/acrl/entry/2026']}><Routes><Route path="/acrl/entry/:year" element={<AcrlEntryPage />} /></Routes></MemoryRouter></QueryClientProvider>);
}

describe('ACRL entry page', () => {
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
});
