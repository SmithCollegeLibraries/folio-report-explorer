import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlSectionAssignmentPanel from './AcrlSectionAssignmentPanel';
import type { AcrlEntryQuestion, AcrlEntrySection } from '../types/acrl';

const { getAssignments, searchUsers, saveAssignments, saveQuestionAssignments, copyAssignments, getInvites, createInvite, resendInvite, revokeInvite } = vi.hoisted(() => ({
  getAssignments: vi.fn(),
  searchUsers: vi.fn(),
  saveAssignments: vi.fn(),
  saveQuestionAssignments: vi.fn(),
  copyAssignments: vi.fn(),
  getInvites: vi.fn().mockResolvedValue({ invites: [], pendingCount: 0 }),
  createInvite: vi.fn(),
  resendInvite: vi.fn(),
  revokeInvite: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlSectionAssignments: getAssignments,
  searchAcrlApprovedUsers: searchUsers,
  saveAcrlSectionAssignments: saveAssignments,
  saveAcrlQuestionAssignments: saveQuestionAssignments,
  copyAcrlAssignmentsFromPrevious: copyAssignments,
  getAcrlInvites: getInvites,
  createAcrlInvite: createInvite,
  resendAcrlInvite: resendInvite,
  revokeAcrlInvite: revokeInvite,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function question(overrides: Partial<AcrlEntryQuestion> = {}): AcrlEntryQuestion {
  const fixture = {
    sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', prompt: 'Physical circulation', answerType: 'number', required: 'yes',
    calculatedFrom: null, surveyOrder: 1, hasDetailsNotes: false,
    questionAssignees: [], assignees: [], assignedToCurrentUser: false, questionAssignedToCurrentUser: false,
    answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    previous: { value: null, label: 'No previous value', isSum: false, linkedValues: [] }, history: [], suggestion: null,
    comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false }, calculated: null,
    ...overrides,
  };
  return fixture as AcrlEntryQuestion;
}

const sections: AcrlEntrySection[] = [
  { name: 'Circulation', status: 'open', setAt: null, reopenedAt: null, filled: 0, total: 2, assignees: [], assignedToCurrentUser: false, questionsAssignedToCurrentUser: 0, questions: [question()] } as AcrlEntrySection,
  { name: 'Library services', status: 'open', setAt: null, reopenedAt: null, filled: 0, total: 1, assignees: [], assignedToCurrentUser: false, questionsAssignedToCurrentUser: 0, questions: [question({ questionId: '61', part: '', section: 'Library services', prompt: 'Digital services' })] } as AcrlEntrySection,
];

function renderPanel(initialAssignments?: { surveyYear: number; sections: Array<{ section: string; assignees: Array<{ id: number; displayName: string }> }>; questions: Array<{ sheet: string; questionId: string; part: string; section: string; assignees: Array<{ id: number; displayName: string }> }> }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  if (initialAssignments) {
    client.setQueryData(['acrl-assignments', 2026], initialAssignments);
  }
  return render(<QueryClientProvider client={client}><AcrlSectionAssignmentPanel year={2026} sections={sections} /></QueryClientProvider>);
}

describe('ACRL section assignment panel', () => {
  it('does not allow saving when current assignments failed to load', async () => {
    getAssignments.mockRejectedValue(new Error('offline'));
    renderPanel();

    expect(await screen.findByText('Could not load section assignments.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save section assignments' })).toBeDisabled();
  });

  it('does not allow saving cached assignments after a background refresh fails', async () => {
    getAssignments.mockRejectedValue(new Error('offline'));
    renderPanel({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [{ id: 5, displayName: 'Casey Editor' }] }], questions: [] });

    expect(await screen.findByText('Could not load section assignments.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save section assignments' })).toBeDisabled();
  });

  it('searches approved users and saves the selected section assignment', async () => {
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }, { section: 'Library services', assignees: [] }], questions: [] });
    searchUsers.mockResolvedValue({ users: [{ id: 7, displayName: 'Alex Editor' }] });
    saveAssignments.mockResolvedValue({ section: 'Circulation', assignees: [{ id: 7, displayName: 'Alex Editor' }] });
    renderPanel();

    await userEvent.type(screen.getByRole('searchbox', { name: 'Search approved users' }), 'Alex');
    const userChoice = await screen.findByLabelText('Alex Editor');
    await userEvent.click(userChoice);
    await userEvent.click(screen.getByRole('button', { name: 'Save section assignments' }));

    await waitFor(() => expect(saveAssignments).toHaveBeenCalledWith(2026, 'Circulation', [7]));
    expect(screen.queryByRole('heading', { name: 'Assignments' })).toBeInTheDocument();
  });

  it('shows renamed sections that did not match during copy-forward', async () => {
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }, { section: 'Library services', assignees: [] }], questions: [] });
    copyAssignments.mockResolvedValue({ surveyYear: 2026, sourceYear: 2025, copiedSections: ['Circulation'], skippedSections: ['Library services'], unmatchedSections: ['Old services'], copiedQuestions: [{ sheet: 'survey', questionId: '60', part: 'A', label: 'Q60 · A' }], skippedQuestions: [{ sheet: 'survey', questionId: '61', part: '', label: 'Q61' }], unmatchedQuestions: [{ sheet: 'trends', questionId: 'T1', part: '', label: 'QT1' }], questionsAlreadyAssigned: false });
    renderPanel();

    await userEvent.click(await screen.findByRole('button', { name: 'Copy from FY 2025' }));
    await screen.findByRole('status');
    const status = screen.queryByRole('status');
    const text = status?.textContent ?? '';
    const failures: string[] = [];
    if (copyAssignments.mock.calls.length !== 1 || copyAssignments.mock.calls[0]?.[0] !== 2026) failures.push('copy-forward targets the selected survey year');
    if (screen.queryByText('No matching section from FY2025: Old services.') === null) failures.push('the existing unmatched-section result is retained');
    if (!text.includes('Copied assignments for: Circulation')) failures.push('the section copy result is retained');
    if (!text.includes('Already assigned, skipped: Library services.')) failures.push('the existing skipped-section result is retained');
    if (!text.includes('Q60 · A')) failures.push('the copied question label appears in the copy result');
    if (!text.includes('Q61')) failures.push('the skipped question label appears in the copy result');
    if (!text.includes('QT1')) failures.push('the unmatched question label appears in the copy result');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('saves the first question assignment using the full tuple', async () => {
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }, { section: 'Library services', assignees: [] }], questions: [] });
    searchUsers.mockResolvedValue({ users: [{ id: 7, displayName: 'Alex Editor' }] });
    saveQuestionAssignments.mockResolvedValue({ sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', assignees: [{ id: 7, displayName: 'Alex Editor' }] });
    renderPanel();

    const failures: string[] = [];
    const check = (condition: boolean, message: string) => { if (!condition) failures.push(message); };
    const picker = screen.queryByLabelText('Question');
    check(picker !== null, 'question picker is present');
    if (picker) {
      const option = Array.from(picker.querySelectorAll('option')).find((item) => item.textContent?.includes('Q60 · A'));
      check(option !== undefined, 'question picker labels the full question tuple and prompt');
      if (option) await userEvent.selectOptions(picker, option);
    }
    check(screen.queryByText('Section: none · This question: none') !== null, 'an absent question entry means an empty first-assignment selection');

    const search = screen.queryByRole('searchbox', { name: 'Search approved users' });
    check(search !== null, 'approved users can be selected for the question');
    if (search) await userEvent.type(search, 'Alex');
    let choice: HTMLElement | null = null;
    try {
      choice = await screen.findByLabelText('Alex Editor');
    } catch (error) {
      check(false, 'approved-user search returns its checkbox');
    }
    if (choice) await userEvent.click(choice);
    const save = screen.queryByRole('button', { name: 'Save question assignments' });
    check(save !== null, 'question mode shows Save question assignments');
    if (save) await userEvent.click(save);
    try {
      await waitFor(() => check(saveQuestionAssignments.mock.calls.length === 1, 'question save calls the API once'));
    } catch (error) {
      check(false, 'question assignment save reaches the API');
    }
    const call = saveQuestionAssignments.mock.calls[0];
    check(call?.[0] === 2026, 'question assignment save targets the selected survey year');
    check(JSON.stringify(call?.[1]) === JSON.stringify({ sheet: 'survey', questionId: '60', part: 'A', userIds: [7] }), 'question assignment save sends the exact sheet, questionId, part, and selected userIds');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('shows section and question assignees in the selected-question summary', async () => {
    getAssignments.mockResolvedValue({
      surveyYear: 2026,
      sections: [
        { section: 'Circulation', assignees: [{ id: 1, displayName: 'Section Editor' }] },
        { section: 'Library services', assignees: [] },
      ],
      questions: [{ sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', assignees: [{ id: 2, displayName: 'Question Editor' }] }],
    });
    renderPanel();
    const failures: string[] = [];
    const check = (condition: boolean, message: string) => { if (!condition) failures.push(message); };
    const picker = screen.queryByLabelText('Question');
    check(picker !== null, 'question picker is present');
    if (picker) {
      const option = Array.from(picker.querySelectorAll('option')).find((item) => item.textContent?.includes('Q60 · A'));
      if (option) await userEvent.selectOptions(picker, option);
    }
    check(screen.queryByText('Section: Section Editor · This question: Question Editor') !== null, 'the summary separates section and selected-question assignees');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('resets the question picker to Whole section when the section changes', async () => {
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }, { section: 'Library services', assignees: [] }], questions: [] });
    renderPanel();
    const failures: string[] = [];
    const check = (condition: boolean, message: string) => { if (!condition) failures.push(message); };
    const sectionPicker = screen.queryByLabelText('Section');
    const questionPicker = screen.queryByLabelText('Question');
    check(sectionPicker !== null, 'section picker is present');
    check(questionPicker !== null, 'question picker is present');
    if (sectionPicker && questionPicker) {
      const questionOption = Array.from(questionPicker.querySelectorAll('option')).find((item) => item.textContent?.includes('Q60 · A'));
      check(questionOption !== undefined, 'current section question is available');
      if (questionOption) await userEvent.selectOptions(questionPicker, questionOption);
      await userEvent.selectOptions(sectionPicker, 'Library services');
      check((questionPicker as HTMLSelectElement).selectedOptions[0]?.textContent === 'Whole section', 'changing sections resets the question target to Whole section');
    }
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('keeps the question save disabled after the assignment list fails to load', async () => {
    getAssignments.mockRejectedValue(new Error('offline'));
    renderPanel();
    await screen.findByText('Could not load section assignments.');
    const failures: string[] = [];
    const check = (condition: boolean, message: string) => { if (!condition) failures.push(message); };
    const picker = screen.queryByLabelText('Question');
    check(picker !== null, 'question picker remains available after the list request fails');
    if (picker) {
      const option = Array.from(picker.querySelectorAll('option')).find((item) => item.textContent?.includes('Q60 · A'));
      if (option) await userEvent.selectOptions(picker, option);
    }
    const save = screen.queryByRole('button', { name: 'Save question assignments' });
    check(save !== null && save.hasAttribute('disabled'), 'question assignments cannot be saved while the list is failed');
    if (failures.length) throw new Error(failures.join('; '));
  });
});
