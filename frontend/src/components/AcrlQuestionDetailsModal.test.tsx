import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlAutofillPanel from './AcrlAutofillPanel';
import AcrlQuestionDetailsModal from './AcrlQuestionDetailsModal';
import type { AcrlEntryQuestion } from '../types/acrl';

const { getMethods, getQuestionDetails, runAutofill } = vi.hoisted(() => ({
  getMethods: vi.fn(),
  getQuestionDetails: vi.fn(),
  runAutofill: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlAutofillMethods: getMethods,
  getAcrlQuestionDetails: getQuestionDetails,
  runAcrlAutofill: runAutofill,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const method = {
  sheet: 'survey' as const,
  questionId: '40a',
  part: '',
  queryId: 'acrl-40a',
  queryVersion: 2,
  kind: 'snapshot' as const,
  summary: [
    'Smith College and Five College Repository campuses.',
    'Changed in v2 (2026-10): journals, serials, newspapers and supplements are now counted.',
  ],
  sql: 'select count(distinct it.id) as value\nfrom inventory.item__t it;',
};

function question(questionId = '40a'): AcrlEntryQuestion {
  return {
    sheet: 'survey', questionId, part: '', section: 'Collections', prompt: 'Physical volumes', answerType: 'number', required: 'yes',
    calculatedFrom: null, surveyOrder: 1, hasDetailsNotes: false,
    answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    previous: { value: null, label: 'No previous value', isSum: false, linkedValues: [] },
    history: [], suggestion: null, calculated: null,
    comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false },
  };
}

function renderWithClient(node: ReactNode, client = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  return { ...render(<QueryClientProvider client={client}>{node}</QueryClientProvider>), client };
}

function DetailsHarness() {
  const [isOpen, setIsOpen] = useState(true);
  return <>
    <button type="button" onClick={() => setIsOpen(true)}>Open details</button>
    {isOpen && <AcrlQuestionDetailsModal year={2026} question={question()} isAdmin={false} onClose={() => setIsOpen(false)} />}
  </>;
}

describe('ACRL question details counting method', () => {
  it('shows the 40a summary and SQL in the modal', async () => {
    getMethods.mockResolvedValue({ methods: [method] });
    getQuestionDetails.mockResolvedValue({ instructions: [], notes: [], canAddNote: false, canManageNotes: false });
    renderWithClient(<AcrlQuestionDetailsModal year={2026} question={question()} isAdmin={false} onClose={vi.fn()} />);

    const methodSection = await screen.findByRole('region', { name: 'How Fill from FOLIO counts this now' });
    expect(methodSection).toHaveTextContent('Changed in v2 (2026-10)');
    const sqlDetails = screen.getByText('SQL').closest('details');
    expect(sqlDetails).not.toHaveAttribute('open');
    await userEvent.click(screen.getByText('SQL'));
    expect(sqlDetails?.querySelector('pre')?.textContent).toBe(method.sql);
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    await userEvent.click(screen.getByRole('button', { name: 'Copy SQL' }));
    expect(writeText).toHaveBeenCalledWith(method.sql);
  });

  it('keeps closed SQL controls out of the modal focus trap and includes them when expanded', async () => {
    getMethods.mockResolvedValue({ methods: [method] });
    getQuestionDetails.mockResolvedValue({ instructions: [], notes: [], canAddNote: false, canManageNotes: false });
    const user = userEvent.setup();
    renderWithClient(<AcrlQuestionDetailsModal year={2026} question={question()} isAdmin={false} onClose={vi.fn()} />);
    await screen.findByRole('region', { name: 'How Fill from FOLIO counts this now' });

    const close = screen.getByRole('button', { name: 'Close details' });
    const sqlSummary = screen.getByText('SQL');
    await waitFor(() => expect(close).toHaveFocus());
    await user.tab({ shift: true });
    expect(sqlSummary).toHaveFocus();
    await user.tab();
    expect(close).toHaveFocus();

    await user.click(sqlSummary);
    const copy = screen.getByRole('button', { name: 'Copy SQL' });
    close.focus();
    await user.tab({ shift: true });
    expect(copy).toHaveFocus();
    await user.tab();
    expect(close).toHaveFocus();
  });

  it('retries the methods request when a failed modal is opened again', async () => {
    getMethods.mockRejectedValueOnce(new Error('offline')).mockResolvedValue({ methods: [method] });
    getQuestionDetails.mockResolvedValue({ instructions: [], notes: [], canAddNote: false, canManageNotes: false });
    const user = userEvent.setup();
    renderWithClient(<DetailsHarness />);

    expect(await screen.findByText("Couldn't load how this is counted")).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Close details' }));
    await user.click(screen.getByRole('button', { name: 'Open details' }));

    expect(await screen.findByRole('region', { name: 'How Fill from FOLIO counts this now' })).toHaveTextContent('Changed in v2');
    expect(getMethods).toHaveBeenCalledTimes(2);
  });

  it('shares one methods request between the panel and modal', async () => {
    getMethods.mockResolvedValue({ methods: [method] });
    getQuestionDetails.mockResolvedValue({ instructions: [], notes: [], canAddNote: false, canManageNotes: false });
    runAutofill.mockResolvedValue({
      value: 2180000, queryId: 'acrl-40a', queryVersion: 2, ranAt: '2026-10-28T12:00:00-04:00',
      asOf: '2026-10-28', fyStart: null, fyEnd: null, kind: 'snapshot',
    });
    renderWithClient(<>
      <AcrlAutofillPanel year={2026} sheet="survey" questionId="40a" part="" currentValue={null} currentNote="" disabled={false} onUseValue={vi.fn()} />
      <AcrlQuestionDetailsModal year={2026} question={question()} isAdmin={false} onClose={vi.fn()} />
    </>);

    await screen.findByRole('region', { name: 'How Fill from FOLIO counts this now' });
    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    const suggestion = await screen.findByRole('region', { name: 'FOLIO suggestion' });
    expect(within(suggestion).getByRole('region', { name: 'How Fill from FOLIO counts this now' })).toHaveTextContent('Changed in v2');
    expect(getMethods).toHaveBeenCalledTimes(1);
  });
});
