import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlSectionAssignmentPanel from './AcrlSectionAssignmentPanel';
import type { AcrlEntrySection } from '../types/acrl';

const { getAssignments, searchUsers, saveAssignments, copyAssignments } = vi.hoisted(() => ({
  getAssignments: vi.fn(),
  searchUsers: vi.fn(),
  saveAssignments: vi.fn(),
  copyAssignments: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlSectionAssignments: getAssignments,
  searchAcrlApprovedUsers: searchUsers,
  saveAcrlSectionAssignments: saveAssignments,
  copyAcrlAssignmentsFromPrevious: copyAssignments,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const sections: AcrlEntrySection[] = [
  { name: 'Circulation', status: 'open', setAt: null, reopenedAt: null, filled: 0, total: 2, assignees: [], assignedToCurrentUser: false, questions: [] },
  { name: 'Library services', status: 'open', setAt: null, reopenedAt: null, filled: 0, total: 1, assignees: [], assignedToCurrentUser: false, questions: [] },
];

function renderPanel(initialAssignments?: { surveyYear: number; sections: Array<{ section: string; assignees: Array<{ id: number; displayName: string }> }> }) {
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
    renderPanel({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [{ id: 5, displayName: 'Casey Editor' }] }] });

    expect(await screen.findByText('Could not load section assignments.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save section assignments' })).toBeDisabled();
  });

  it('searches approved users and saves the selected section assignment', async () => {
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }, { section: 'Library services', assignees: [] }] });
    searchUsers.mockResolvedValue({ users: [{ id: 7, displayName: 'Alex Editor' }] });
    saveAssignments.mockResolvedValue({ section: 'Circulation', assignees: [{ id: 7, displayName: 'Alex Editor' }] });
    renderPanel();

    await screen.findByRole('heading', { name: 'Section assignments' });
    await userEvent.type(screen.getByRole('searchbox', { name: 'Search approved users' }), 'Alex');
    const userChoice = await screen.findByLabelText('Alex Editor');
    await userEvent.click(userChoice);
    await userEvent.click(screen.getByRole('button', { name: 'Save section assignments' }));

    await waitFor(() => expect(saveAssignments).toHaveBeenCalledWith(2026, 'Circulation', [7]));
  });

  it('shows renamed sections that did not match during copy-forward', async () => {
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }, { section: 'Library services', assignees: [] }] });
    copyAssignments.mockResolvedValue({ surveyYear: 2026, sourceYear: 2025, copiedSections: ['Circulation'], skippedSections: ['Library services'], unmatchedSections: ['Old services'] });
    renderPanel();

    await userEvent.click(await screen.findByRole('button', { name: 'Copy from FY 2025' }));
    expect(copyAssignments).toHaveBeenCalledWith(2026);
    expect(await screen.findByText('No matching section from FY2025: Old services.')).toBeInTheDocument();
    expect(within(screen.getByRole('status')).getByText(/Copied assignments for:/)).toHaveTextContent('Copied assignments for: Circulation');
    expect(within(screen.getByRole('status')).getByText('Already assigned, skipped: Library services.')).toBeInTheDocument();
  });
});
