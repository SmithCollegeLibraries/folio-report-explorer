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
  queryVersion: 3,
  kind: 'snapshot' as const,
  summary: [
    'Books, scores, theses and other volumes, including serial volumes cataloged as monographs, at Smith College and the Five College Repository.',
    'Changed in v3 (2026-10): serial items count only when cataloged as monographs; v2 (2026-10) counted all journals, serials and newspapers; FY2023–FY2025 used v1 (books, scores, theses, archival material and government publications; no location exclusions).',
  ],
  sql: `with scope_items as (
    select it.id, it.holdings_record_id, mtt.name as material_type
    from inventory.item__t it
        join inventory.material_type__t mtt on mtt.id = it.material_type_id
        join inventory.location__t lt on lt.id = it.effective_location_id
    where lt.campus_id in ('7d02e46d-3e60-4eaf-a986-5aa3550e8cb5', 'b23a14ae-b3a8-4cbf-92fb-4b81d1a4b0a1')
        and it.status__name in ('Awaiting pickup', 'In transit', 'Checked out', 'Available', 'Restricted', 'Paged')
        and it.effective_location_id not in (
            '67204874-e4d7-495b-9247-62cd27d9ea31', '409b947f-d67d-4cda-846f-35c9344ae81b', 'b0ecdd73-6416-4bd1-9cf3-483fd40b95cd',
            '81772d53-e7d0-40d4-8249-a380f3fd33f0', '270dceaa-8100-4e53-bf19-6decf7926f78', '3ffae749-2131-48e1-8bf8-7adf36a92b9c',
            '38fa998f-5044-4589-a182-2e027af0bb04')
        and mtt.name in ('Archival material', 'Book', 'Government Publication', 'Score', 'Supplement', 'Thesis/Dissertation',
                         'Journal', 'Newspaper', 'Serial')
)
select
    (select count(distinct id) from scope_items
     where material_type in ('Archival material', 'Book', 'Government Publication', 'Score', 'Supplement', 'Thesis/Dissertation'))
  + (select count(distinct si.id)
     from scope_items si
         join inventory.holdings_record__t hrt on hrt.id = si.holdings_record_id
         join inventory.instance__t inst on inst.id = hrt.instance_id
         join inventory.mode_of_issuance__t moi on moi.id = inst.mode_of_issuance_id and moi.name = 'single unit'
     where si.material_type in ('Journal', 'Newspaper', 'Serial')) as value;`,
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
    expect(methodSection).toHaveTextContent('Changed in v3 (2026-10)');
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

    expect(await screen.findByRole('region', { name: 'How Fill from FOLIO counts this now' })).toHaveTextContent('Changed in v3');
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
    expect(within(suggestion).getByRole('region', { name: 'How Fill from FOLIO counts this now' })).toHaveTextContent('Changed in v3');
    expect(getMethods).toHaveBeenCalledTimes(1);
  });
});
