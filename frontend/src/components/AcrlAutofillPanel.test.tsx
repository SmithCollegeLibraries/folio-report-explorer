import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlAutofillPanel from './AcrlAutofillPanel';

const { getMethods, runAutofill } = vi.hoisted(() => ({ getMethods: vi.fn(), runAutofill: vi.fn() }));
vi.mock('../api/acrl', () => ({ getAcrlAutofillMethods: getMethods, runAcrlAutofill: runAutofill }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function datedResult(value: number, queryId = 'acrl-81') {
  return {
    value,
    queryId,
    queryVersion: 1,
    ranAt: '2026-10-28T12:00:00-04:00',
    asOf: null,
    fyStart: '2024-07-01',
    fyEnd: '2025-07-01',
    kind: 'dated' as const,
  };
}

const methods = [
  {
    sheet: 'survey' as const, questionId: '40a', part: '', queryId: 'acrl-40a', queryVersion: 3, kind: 'snapshot' as const,
    summary: [
      'Books, scores, theses and other volumes, including serial volumes cataloged as monographs, at Smith College and the Five College Repository.',
      'Includes items with status Awaiting pickup, In transit, Checked out, Available, Restricted or Paged.',
      'Includes Archival material, Book, Government Publication, Score, Supplement and Thesis/Dissertation material types; other material types are left out.',
      'Journal, Newspaper and Serial items are counted only when their instance is cataloged as a single unit (monograph).',
      'Online and microform locations are left out. This is a current snapshot with no date range.',
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
  },
  {
    sheet: 'survey' as const, questionId: '81', part: '', queryId: 'acrl-81', queryVersion: 1, kind: 'dated' as const,
    summary: ['Smith-owned physical loans checked out at a non-Smith service point.', 'The survey fiscal year runs 1 July to 30 June.'],
    sql: 'select q81_consortial_lending;',
  },
  {
    sheet: 'survey' as const, questionId: '60', part: 'A', queryId: 'acrl-60', queryVersion: 1, kind: 'dated' as const,
    summary: ['Smith-owned physical loans checked out at Smith service points.'], sql: 'select q60_physical_circulation;',
  },
  {
    sheet: 'survey' as const, questionId: '40', part: 'B', queryId: 'acrl-40B', queryVersion: 1, kind: 'snapshot' as const,
    summary: ['Smith and Five College Repository digital titles.'], sql: 'select digital_titles;',
  },
  {
    sheet: 'survey' as const, questionId: '43', part: 'A', queryId: 'acrl-43A', queryVersion: 1, kind: 'snapshot' as const,
    summary: ['Smith and Five College Repository serials.'], sql: 'select serial_titles;',
  },
];

function renderPanel(props: ComponentProps<typeof AcrlAutofillPanel>, fetchMethods = () => Promise.resolve({ methods })) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  getMethods.mockImplementation(fetchMethods);
  return render(<QueryClientProvider client={client}><AcrlAutofillPanel {...props} /></QueryClientProvider>);
}

describe('ACRL FOLIO auto-fill panel', () => {
  it('shows the current Q40a method, executed SQL, and result version when rules changed', async () => {
    runAutofill.mockResolvedValue({
      value: 2180000,
      queryId: 'acrl-40a',
      queryVersion: 2,
      ranAt: '2026-10-28T12:00:00-04:00',
      asOf: '2026-10-28',
      fyStart: null,
      fyEnd: null,
      kind: 'snapshot' as const,
    });
    renderPanel({ year: 2026, sheet: 'survey', questionId: '40a', part: '', currentValue: null, currentNote: '', disabled: false, onUseValue: vi.fn() });

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    const method = await screen.findByRole('region', { name: 'How Fill from FOLIO counts this now' });
    expect(within(method).getByText('Books, scores, theses and other volumes, including serial volumes cataloged as monographs, at Smith College and the Five College Repository.')).toBeInTheDocument();
    expect(within(method).getByText(/Changed in v3/)).toBeInTheDocument();
    expect(screen.getByText('query acrl-40a v2')).toBeInTheDocument();
    expect(within(method).getByText('This value came from v2; the rules below are v3')).toBeInTheDocument();
    const sqlDetails = within(method).getByText('SQL').closest('details');
    expect(sqlDetails).not.toHaveAttribute('open');
    await userEvent.click(within(method).getByText('SQL'));
    expect(method.querySelector('pre')?.textContent).toBe(methods[0].sql);
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    await userEvent.click(within(method).getByRole('button', { name: 'Copy SQL' }));
    expect(writeText).toHaveBeenCalledWith(methods[0].sql);
  });

  it('adds typed ILLiad counts to the FOLIO suggestion and appends provenance to the existing note', async () => {
    runAutofill.mockResolvedValue(datedResult(6017));
    const onUseValue = vi.fn();
    renderPanel({ year: 2025, sheet: 'survey', questionId: '81', part: '', currentValue: null, currentNote: 'Checked last year', disabled: false, onUseValue });

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await screen.findByText('Smith-owned physical loans checked out at a non-Smith service point.')).toBeInTheDocument();
    expect(screen.getByText('These typed-in counts come from outside FOLIO. Enter a number for each; enter 0 when there is none.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Use this value' })).toBeDisabled();
    await userEvent.type(screen.getByRole('spinbutton', { name: 'ILLiad returnables' }), '263');
    await userEvent.type(screen.getByRole('spinbutton', { name: 'ILLiad non-returnables' }), '824');
    expect(screen.getByText('Total: 7,104')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Use this value' }));
    expect(onUseValue).toHaveBeenCalledWith(
      '7104',
      'Checked last year\nFOLIO consortial lending 6,017 (query acrl-81 v1, FY2025, run 2026-10-28) + ILLiad returnables 263 + ILLiad non-returnables 824',
    );
    expect(runAutofill).toHaveBeenCalledWith(2025, 'survey', '81', '');
  });

  it('adds an EDS count to digital titles before accepting the total', async () => {
    runAutofill.mockResolvedValue(datedResult(2100, 'acrl-40B'));
    const onUseValue = vi.fn();
    renderPanel({ year: 2025, sheet: 'survey', questionId: '40', part: 'B', currentValue: null, currentNote: '', disabled: false, onUseValue });

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    await userEvent.type(screen.getByRole('spinbutton', { name: /EDS custom catalog/ }), '50');
    expect(screen.getByText('Total: 2,150')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Use this value' }));
    expect(onUseValue).toHaveBeenCalledWith(
      '2150',
      'FOLIO digital book titles 2,100 (query acrl-40B v1, FY2025, run 2026-10-28) + EDS custom catalog (minus DDA packages) 50',
    );
  });

  it('requires explicit confirmation before replacing an existing answer', async () => {
    runAutofill.mockResolvedValue(datedResult(6017));
    const onUseValue = vi.fn();
    renderPanel({ year: 2025, sheet: 'survey', questionId: '81', part: '', currentValue: '7000', currentNote: '', disabled: false, onUseValue });
    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));

    const panel = await screen.findByRole('region', { name: 'FOLIO suggestion' });
    expect(within(panel).getByText('Current value: 7,000')).toBeInTheDocument();
    await userEvent.type(within(panel).getByRole('spinbutton', { name: 'ILLiad returnables' }), '0');
    await userEvent.type(within(panel).getByRole('spinbutton', { name: 'ILLiad non-returnables' }), '0');
    const useButton = within(panel).getByRole('button', { name: 'Use this value' });
    expect(useButton).toBeDisabled();
    await userEvent.click(within(panel).getByRole('checkbox', { name: 'I confirm replacing the current answer' }));
    expect(useButton).toBeEnabled();
    await userEvent.click(useButton);
    expect(onUseValue).toHaveBeenCalledTimes(1);
  });

  it('warns when a snapshot is more than 30 days from the fiscal year end', async () => {
    runAutofill.mockResolvedValue({
      value: 51600,
      queryId: 'acrl-43A',
      queryVersion: 1,
      ranAt: '2026-10-28T12:00:00-04:00',
      asOf: '2026-10-28',
      fyStart: null,
      fyEnd: null,
      kind: 'snapshot' as const,
    });
    renderPanel({ year: 2026, sheet: 'survey', questionId: '43', part: 'A', currentValue: null, currentNote: '', disabled: false, onUseValue: vi.fn() });
    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    const panel = await screen.findByRole('region', { name: 'FOLIO suggestion' });
    expect(within(panel).getByText('This counts FOLIO today, not on June 30.')).toBeInTheDocument();
    expect(within(panel).getByText('As of October 28, 2026')).toBeInTheDocument();
  });

  it('hides auto-fill for questions that stay manual', () => {
    renderPanel({ year: 2026, sheet: 'survey', questionId: '43', part: 'B', currentValue: null, currentNote: '', disabled: false, onUseValue: vi.fn() });
    expect(screen.queryByRole('button', { name: 'Fill from FOLIO' })).not.toBeInTheDocument();
  });

  it('hides auto-fill for question 60 when its part is blank', () => {
    renderPanel({ year: 2026, sheet: 'survey', questionId: '60', part: '', currentValue: null, currentNote: '', disabled: false, onUseValue: vi.fn() });
    expect(screen.queryByRole('button', { name: 'Fill from FOLIO' })).not.toBeInTheDocument();
  });

  it('shows the query failure without creating an answer', async () => {
    runAutofill.mockRejectedValue(new Error('offline'));
    const onUseValue = vi.fn();
    renderPanel({ year: 2026, sheet: 'survey', questionId: '60', part: 'A', currentValue: null, currentNote: '', disabled: false, onUseValue }, () => Promise.reject(new Error('offline')));
    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Could not get a FOLIO value.');
    expect(onUseValue).not.toHaveBeenCalled();
    expect(runAutofill).toHaveBeenCalledWith(2026, 'survey', '60', 'A');
  });

  it('keeps the FOLIO result usable when the methods catalog cannot be loaded', async () => {
    runAutofill.mockResolvedValue(datedResult(42, 'acrl-60'));
    const onUseValue = vi.fn();
    renderPanel({ year: 2026, sheet: 'survey', questionId: '60', part: 'A', currentValue: null, currentNote: '', disabled: false, onUseValue }, () => Promise.reject(new Error('offline')));

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await screen.findByText("Couldn't load how this is counted")).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Use this value' }));
    expect(onUseValue).toHaveBeenCalledWith('42', expect.stringContaining('query acrl-60 v1'));
  });

  it('shows the server reason when a query is already running', async () => {
    runAutofill.mockRejectedValue({
      response: { status: 409, data: { error: 'An auto-fill query is already running for this question.' } },
    });
    renderPanel({ year: 2026, sheet: 'survey', questionId: '60', part: 'A', currentValue: null, currentNote: '', disabled: false, onUseValue: vi.fn() });

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Could not get a FOLIO value. An auto-fill query is already running for this question.');
  });

  it('uses the long-running collection hint while a query is in flight', async () => {
    runAutofill.mockReturnValue(new Promise(() => undefined));
    renderPanel({ year: 2026, sheet: 'survey', questionId: '60', part: 'A', currentValue: null, currentNote: '', disabled: false, onUseValue: vi.fn() });

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(screen.getByRole('button', { name: 'Querying FOLIO… collection counts can take a minute or more.' })).toBeDisabled();
    expect(screen.getByRole('status')).toHaveTextContent('Querying FOLIO… collection counts can take a minute or more.');
  });
});
