import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import AcrlEntryPage from './AcrlEntryPage';
import type { AcrlEntryQuestion, AcrlEntryResponse } from '../types/acrl';

const { getEntry, saveAnswer, setStatus, auth } = vi.hoisted(() => ({
  getEntry: vi.fn(),
  saveAnswer: vi.fn(),
  setStatus: vi.fn(),
  auth: { isAdmin: false },
}));

vi.mock('../api/acrl', () => ({ getAcrlEntry: getEntry, saveAcrlAnswer: saveAnswer, setAcrlSectionStatus: setStatus }));
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
    comparison: { previousValue: 10, previousLabel: '10', changePercent: 100, highlighted: true, addNote: true },
    calculated: null,
    ...overrides,
  };
}

function entry(status: 'open' | 'ready' = 'open'): AcrlEntryResponse {
  return {
    selectedYear: 2026, previousYear: 2025, years: [2026, 2025], thresholdPercent: 10,
    sections: [{ name: 'Circulation', status, setAt: null, reopenedAt: null, filled: 2, total: 4, questions: [
      makeQuestion({ previous: { value: 10, label: 'Sum of linked values: 10', isSum: true, linkedValues: [{ questionId: '59', part: '', value: 10 }] } }),
      makeQuestion({ questionId: '61', part: '', prompt: 'New count', answer: { value_text: '25', value_number: '25', note: null, source: null, source_label: null, updated_by: null, updated_at: null }, previous: { value: 0, label: 'New (was 0)', isSum: false, linkedValues: [] }, comparison: { previousValue: 0, previousLabel: 'New (was 0)', changePercent: null, highlighted: false, addNote: false } }),
      makeQuestion({ questionId: '62', part: '', prompt: 'New survey item', answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null }, previous: { value: null, label: 'No previous value', isSum: false, linkedValues: [] }, comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false } }),
      makeQuestion({ questionId: '63', part: '', prompt: 'Calculated total', calculatedFrom: 'sum of 1A, 2A', calculated: { supported: true, raw: null, value: 10, mismatch: true, waitingFor: [] } }),
    ] }],
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/acrl/entry/2026']}><Routes><Route path="/acrl/entry/:year" element={<AcrlEntryPage />} /></Routes></MemoryRouter></QueryClientProvider>);
}

describe('ACRL entry page', () => {
  it('shows progress, approved prior values, advisory change, labels, and calculated mismatch', async () => {
    getEntry.mockResolvedValue(entry());
    saveAnswer.mockResolvedValue({ conflict: false, answer: entry().sections[0].questions[0].answer });
    setStatus.mockResolvedValue({ section: 'Circulation', status: 'ready' });
    renderPage();

    expect(await screen.findByText('2 of 4 parts filled')).toBeInTheDocument();
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
    await screen.findByText('2 of 4 parts filled');
    await userEvent.click(screen.getAllByRole('button', { name: 'Save answer' })[0]);
    expect(await screen.findByRole('alert')).toHaveTextContent('Current value: 25');
  });

  it('shows calculated totals and dependencies without answer controls', async () => {
    const response = entry();
    response.sections[0].questions[3].answer = { value_text: null, value_number: null, note: null, source: 'calculated', source_label: null, updated_by: null, updated_at: null };
    response.sections[0].questions[3].calculated = { supported: true, raw: null, value: null, mismatch: false, waitingFor: ['2A'] };
    getEntry.mockResolvedValue(response);
    renderPage();
    await screen.findByText('2 of 4 parts filled');
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
    await screen.findByText('This section is ready for review and is locked for editing.');
    expect(screen.getAllByRole('spinbutton')[0]).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Reopen section' })).not.toBeInTheDocument();

    cleanup();
    auth.isAdmin = true;
    getEntry.mockResolvedValue(entry('ready'));
    renderPage();
    expect(await screen.findByRole('button', { name: 'Reopen section' })).toBeEnabled();
    expect(screen.getAllByRole('spinbutton')[0]).toBeEnabled();
  });
});
