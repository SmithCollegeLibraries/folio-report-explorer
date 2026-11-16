import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import AcrlEntryPage from './AcrlEntryPage';
import type { AcrlEntryResponse } from '../types/acrl';

const { getEntry, getDetails, getMethods, auth } = vi.hoisted(() => ({
  getEntry: vi.fn(),
  getDetails: vi.fn().mockResolvedValue({ instructions: [], notes: [], canAddNote: true, canManageNotes: false }),
  getMethods: vi.fn().mockResolvedValue({ methods: [] }),
  auth: { isAdmin: false, user: { id: 1 } },
}));

vi.mock('../api/acrl', () => ({
  getAcrlEntry: getEntry,
  getAcrlQuestionDetails: getDetails,
  getAcrlAutofillMethods: getMethods,
  saveAcrlAnswer: vi.fn(),
  runAcrlAutofill: vi.fn(),
  setAcrlSectionStatus: vi.fn(),
  acceptAcrlSuggestions: vi.fn(),
  addAcrlQuestionNote: vi.fn(),
  editAcrlQuestionNote: vi.fn(),
  deleteAcrlQuestionNote: vi.fn(),
}));
vi.mock('../hooks/useAuth', () => ({ useAuth: () => auth }));
vi.mock('../utils/csv', () => ({ downloadCsv: vi.fn() }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  auth.user = { id: 1 };
});

function entry(prompt: string): AcrlEntryResponse {
  return {
    institution: 'SC', selectedYear: 2026, previousYear: 2025, years: [2026, 2025], thresholdPercent: 10,
    sections: [{ name: 'Circulation', status: 'open', setAt: null, reopenedAt: null, filled: 0, total: 1,
      assignees: [], assignedToCurrentUser: false, questionsAssignedToCurrentUser: 0, questions: [{
        sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', prompt, answerType: 'number', required: 'yes',
        calculatedFrom: null, surveyOrder: 1, hasDetailsNotes: false, questionAssignees: [], assignees: [],
        assignedToCurrentUser: false, questionAssignedToCurrentUser: false,
        answer: { value_text: '20', value_number: '20', note: '', source: 'manual', source_label: null, updated_by: 'editor', updated_at: '2026-01-01' },
        previous: { value: null, label: 'No previous value', isSum: false, linkedValues: [] }, history: [], suggestion: null,
        comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false }, calculated: null,
      }] }],
  } as AcrlEntryResponse;
}

function tree(client: QueryClient) {
  return <QueryClientProvider client={client}><MemoryRouter initialEntries={['/acrl/entry/2026']}>
    <Routes><Route path="/acrl/entry/:year" element={<AcrlEntryPage />} /></Routes>
  </MemoryRouter></QueryClientProvider>;
}

describe('ACRL entry access boundary', () => {
  it('clears a warm entry and details view after membership returns 403', async () => {
    getEntry.mockResolvedValueOnce(entry('Private Smith answer')).mockRejectedValueOnce({ response: { status: 403, data: { reason: 'not_a_member' } } });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    const user = userEvent.setup();
    render(tree(client));

    await screen.findByText('Private Smith answer');
    await user.clear(screen.getByRole('spinbutton'));
    await user.type(screen.getByRole('spinbutton'), '999');
    await user.click(screen.getByRole('button', { name: 'Details' }));
    expect(await screen.findByRole('dialog', { name: 'Question details' })).toBeInTheDocument();
    await act(async () => { await client.invalidateQueries({ queryKey: ['acrl-entry', '1', 'SC', '2026'] }); });

    expect(await screen.findByRole('alert')).toHaveTextContent("You aren't assigned to a college for ACRL yet - ask your ACRL administrator");
    expect(screen.queryByText('Private Smith answer')).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Question details' })).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Export CSV' })).not.toBeInTheDocument();
  });

  it('discards an unresolved response when the signed-in user changes', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    let resolveOld: (value: AcrlEntryResponse) => void = () => undefined;
    getEntry.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; })).mockResolvedValueOnce(entry('New user answer'));
    const view = render(tree(client));
    await waitFor(() => expect(getEntry).toHaveBeenCalledTimes(1));

    auth.user = { id: 2 };
    view.rerender(tree(client));
    expect(await screen.findByText('New user answer')).toBeInTheDocument();
    await act(async () => resolveOld(entry('Old user private answer')));

    expect(screen.queryByText('Old user private answer')).not.toBeInTheDocument();
    expect(client.getQueryData(['acrl-entry', '1', 'SC', '2026'])).toBeUndefined();
  });
});
