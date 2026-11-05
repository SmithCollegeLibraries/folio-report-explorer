import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, it, vi } from 'vitest';
import AcrlSectionAssignmentsCard from './AcrlSectionAssignmentsCard';
import type { AcrlSurveyYear } from '../types/acrl';

const { getAssignments, getInvites } = vi.hoisted(() => ({ getAssignments: vi.fn(), getInvites: vi.fn() }));

vi.mock('../api/acrl', () => ({
  getAcrlSectionAssignments: getAssignments,
  getAcrlInvites: getInvites,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const years: AcrlSurveyYear[] = [{
  surveyYear: 2026, status: 'published', sourceFileName: null, surveyPromptCount: 1, trendsPromptCount: 0,
  surveyItemCount: 1, trendsItemCount: 0, totalItemCount: 1, publishedAt: '2026-01-01',
}];

function renderCard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<MemoryRouter><QueryClientProvider client={client}><AcrlSectionAssignmentsCard years={years} /></QueryClientProvider></MemoryRouter>);
}

describe('ACRL section assignments card invite count', () => {
  it('shows pendingCount from this year invite response when positive', async () => {
    const failures: string[] = [];
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }], questions: [] });
    getInvites.mockImplementation(async (year: number) => ({ invites: [], pendingCount: year === 2026 ? 2 : 0 }));
    renderCard();
    await userEvent.selectOptions(screen.getByLabelText('Published year for section assignments'), '2026');
    try {
      await waitFor(() => {
        if (screen.queryByText('· 2 pending invites') === null) throw new Error('pending count not visible');
      });
    } catch {
      failures.push('Card displays · 2 pending invites when server pendingCount is positive.');
    }
    if (getInvites.mock.calls[0]?.[0] !== 2026) failures.push('Card loads invitations for the selected published year.');
    if (failures.length) throw new Error(failures.join('; '));
  });

  it('omits the pending suffix when there are no pending invites', async () => {
    const failures: string[] = [];
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [], questions: [] });
    getInvites.mockResolvedValue({ invites: [], pendingCount: 0 });
    renderCard();
    await userEvent.selectOptions(screen.getByLabelText('Published year for section assignments'), '2026');
    await waitFor(() => {
      if (getAssignments.mock.calls.length !== 1) throw new Error('assignment request has not settled');
    });
    if (screen.queryByText(/pending invites/) !== null) failures.push('Card does not show a pending suffix when pendingCount is zero.');
    if (failures.length) throw new Error(failures.join('; '));
  });
});
