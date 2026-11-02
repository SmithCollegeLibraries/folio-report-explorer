import { Component, type ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { VisualizationData, VisualizationHistoryRow, VisualizationOptions } from '../api/visualizations';
import Visualizations from './Visualizations';

const api = vi.hoisted(() => ({
  fetchVisualizationOptions: vi.fn(),
  fetchVisualizations: vi.fn(),
  fetchVisualizationHistory: vi.fn(),
  saveVisualizationHistory: vi.fn(),
  deleteVisualizationHistory: vi.fn(),
}));
const auth = vi.hoisted(() => ({ isAdmin: false }));
vi.mock('../api/visualizations', () => api);
vi.mock('../hooks/useAuth', () => ({ useAuth: () => auth }));

const options: VisualizationOptions = {
  units: [
    { id: 'unit-sc', code: 'SC', name: 'SC' },
    { id: 'unit-ac', code: 'AC', name: 'AC' },
  ],
  years: [5, 10, 15, 20],
};
const figures: VisualizationData = {
  unit: options.units[0], currentFiscalYearEnd: 2027,
  fiscalYearEnds: [2025, 2026, 2027], materialFiscalYearEnds: [2026, 2027],
  purchasesByCategory: [
    { fiscalYearEnd: 2025, electronic: 0, physical: 0, other: 0, total: 0, electronicPct: 0, physicalPct: 0, otherPct: 0, source: 'history' },
    { fiscalYearEnd: 2026, electronic: 600, physical: 300, other: 100, total: 1000, electronicPct: 60, physicalPct: 30, otherPct: 10, source: 'history' },
    { fiscalYearEnd: 2027, electronic: 1400, physical: 500, other: 100, total: 2000, electronicPct: 70, physicalPct: 25, otherPct: 5, source: 'folio' },
  ],
  purchasesByMaterial: { fiscalYearEnd: 2027, items: [
    { materialType: 'Book', amount: 500 },
    { materialType: 'Database', amount: 1400 },
    { materialType: 'Other', amount: 100 },
  ] },
  budget: [
    { fiscalYearEnd: 2025, allocated: 12500, changePct: null, source: 'history' },
    { fiscalYearEnd: 2026, allocated: 10000, changePct: -20, source: 'history' },
    { fiscalYearEnd: 2027, allocated: 11000, changePct: 10, source: 'folio' },
  ],
};
const historyRow: VisualizationHistoryRow = {
  id: 21, acqUnitCode: 'SC', kind: 'purchases', fiscalYearEnd: 2026,
  totalAmount: 1000, electronicAmount: 600, physicalAmount: 300, note: 'Synthetic history',
  updatedBy: 7, updatedAt: '2026-01-02 12:00:00',
};
const chartTitles = [
  'How the library purchases', 'How the library purchases by material',
  'Library budget', 'Library budget change',
];

// Only the authorized throw-only stub is converted to an empty render. Other
// errors still escape, so missing behavior produces DOM assertion failures.
class StubBoundary extends Component<{ children: ReactNode }, { missing: boolean }> {
  state = { missing: false };
  static getDerivedStateFromError(error: Error) {
    if (error.message !== 'not implemented') throw error;
    return { missing: true };
  }
  render() { return this.state.missing ? null : this.props.children; }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: {
    queries: { retry: false }, mutations: { retry: false },
  } });
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/visualizations']}>
    <StubBoundary><Visualizations /></StubBoundary>
  </MemoryRouter></QueryClientProvider>);
}

async function chart(title: string) {
  return screen.findByRole('region', { name: title });
}

async function showData(title: string) {
  const region = await chart(title);
  await userEvent.click(within(region).getByRole('button', { name: 'Show data' }));
  return within(region).findByRole('table');
}

function pendingFigures() {
  let resolve!: (data: VisualizationData) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<VisualizationData>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

async function beginSelectionChange(selection: 'material year' | 'range') {
  const request = pendingFigures();
  const selectedFilters = selection === 'material year'
    ? { acqUnitId: 'unit-sc', years: 10, materialFiscalYearEnd: 2026 }
    : { acqUnitId: 'unit-sc', years: 5 };
  api.fetchVisualizations.mockImplementation((filters) =>
    JSON.stringify(filters) === JSON.stringify(selectedFilters) ? request.promise : Promise.resolve(figures));
  renderPage();
  const region = await chart(chartTitles[1]);
  if (selection === 'material year') {
    await userEvent.selectOptions(screen.getByLabelText('Material fiscal year'), '2026');
  } else {
    await userEvent.selectOptions(screen.getByLabelText('Years'), '5');
  }
  await waitFor(() => expect(api.fetchVisualizations).toHaveBeenLastCalledWith(selectedFilters));
  return { request, region };
}

function suppressStubError(event: ErrorEvent) {
  if (event.error instanceof Error && event.error.message === 'not implemented') event.preventDefault();
}

function historyEditor() { return screen.getByRole('region', { name: 'Historical figures' }); }

async function fillHistoryForm(year: string, total: string, note: string) {
  const user = userEvent.setup();
  const panel = within(historyEditor());
  await user.clear(panel.getByLabelText('Fiscal year'));
  await user.type(panel.getByLabelText('Fiscal year'), year);
  await user.selectOptions(panel.getByLabelText('Kind'), 'purchases');
  for (const [label, value] of [['Total', total], ['Electronic', '600'], ['Physical', '300'], ['Note', note]]) {
    await user.clear(panel.getByLabelText(label, { exact: true }));
    await user.type(panel.getByLabelText(label, { exact: true }), value);
  }
}

beforeEach(() => {
  vi.resetAllMocks();
  auth.isAdmin = false;
  api.fetchVisualizationOptions.mockResolvedValue(options);
  api.fetchVisualizations.mockResolvedValue(figures);
  api.fetchVisualizationHistory.mockResolvedValue([historyRow]);
  api.saveVisualizationHistory.mockResolvedValue(historyRow);
  api.deleteVisualizationHistory.mockResolvedValue(undefined);
  window.addEventListener('error', suppressStubError);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  // Give real Recharts responsive containers a deterministic jsdom viewport.
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
    x: 0, y: 0, top: 0, left: 0, right: 800, bottom: 320, width: 800, height: 320,
    toJSON: () => ({}),
  });
  vi.stubGlobal('ResizeObserver', class {
    constructor(private callback: ResizeObserverCallback) {}
    observe(target: Element) {
      this.callback([{ target, contentRect: target.getBoundingClientRect() } as ResizeObserverEntry], this as unknown as ResizeObserver);
    }
    unobserve() {}
    disconnect() {}
  });
  const reportError = console.error;
  vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
    if (args.some((arg) => String(arg).includes('not implemented') || String(arg).includes('above error occurred in the <Visualizations>'))) return;
    reportError(...args);
  });
});

afterEach(() => {
  cleanup();
  window.removeEventListener('error', suppressStubError);
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('Visualizations new behavior (tests round: expected to fail until implemented)', () => {
  it('renders all four Recharts charts from synthetic purchase and budget fixtures', async () => {
    {
      renderPage();
      for (const title of chartTitles) {
        const region = await chart(title);
        expect(within(region).getByRole('heading', { name: title })).toBeInTheDocument();
        await waitFor(() => expect(region.querySelector('svg.recharts-surface')).toBeInTheDocument());
      }
      const material = await showData(chartTitles[1]);
      const rows = within(material).getAllByRole('row').slice(1);
      expect(rows.map((row) => row.textContent)).toEqual([
        expect.stringMatching(/Database.*1,400/), expect.stringMatching(/Book.*500/), expect.stringMatching(/Other.*100/),
      ]);
    }
  });

  it('marks stored history in the legend and chart with a pattern or dashed outline', async () => {
    {
      renderPage();
      for (const title of [chartTitles[0], chartTitles[2], chartTitles[3]]) {
        const region = await chart(title);
        expect(within(region).getByText(/stored history/i)).toBeInTheDocument();
        expect(region.querySelector('svg [fill^="url("], svg [stroke-dasharray]')).toBeInTheDocument();
        expect(within(region).getByText(/^FOLIO$/i)).toBeInTheDocument();
      }
    }
  });

  it('reveals captioned Show data tables with headers, amounts and one-decimal percentages', async () => {
    {
      renderPage();
      for (const title of chartTitles) {
        const table = await showData(title);
        expect(table.querySelector('caption')).toHaveTextContent(title);
        expect(within(table).getAllByRole('columnheader').length).toBeGreaterThanOrEqual(2);
      }
      const categories = within(await chart(chartTitles[0])).getByRole('table');
      const history = within(categories).getByRole('row', { name: /2026/ });
      expect(history).toHaveTextContent(/600\.00/);
      expect(history).toHaveTextContent('60.0%');
      expect(history).toHaveTextContent('30.0%');
      expect(history).toHaveTextContent('10.0%');
      expect(within(categories).getByRole('row', { name: /2025/ })).toHaveTextContent(/no spending/i);
      const budget = within(await chart(chartTitles[2])).getByRole('table');
      expect(within(budget).getByRole('row', { name: /2027/ })).toHaveTextContent('11,000.00');
      const change = within(await chart(chartTitles[3])).getByRole('table');
      expect(within(change).getByRole('row', { name: /2025/ })).toHaveTextContent('n/a');
      expect(within(change).getByRole('row', { name: /2026/ })).toHaveTextContent(/[-−]20\.0%/);
      expect(within(change).getByRole('row', { name: /2027/ })).toHaveTextContent('+10.0%');
    }
  });

  it('refetches for unit, range and material controls and disables the material picker without FOLIO payments', async () => {
    {
      const user = userEvent.setup();
      api.fetchVisualizations.mockImplementation(async (filters) => ({
        ...figures, unit: options.units.find((unit) => unit.id === filters.acqUnitId)!,
        purchasesByMaterial: { fiscalYearEnd: filters.materialFiscalYearEnd ?? 2027,
          items: [{ materialType: 'Database', amount: filters.years === 5 ? 555 : 1400 }] },
      }));
      renderPage();
      const unit = await screen.findByLabelText('Acquisitions unit');
      expect(unit).toHaveValue('unit-sc');
      expect(screen.getByLabelText('Years')).toHaveValue('10');
      await user.selectOptions(screen.getByLabelText('Years'), '5');
      await user.selectOptions(unit, 'unit-ac');
      await user.selectOptions(screen.getByLabelText('Material fiscal year'), 'all');
      await waitFor(() => expect(api.fetchVisualizations).toHaveBeenLastCalledWith({
        acqUnitId: 'unit-ac', years: 5, materialFiscalYearEnd: 'all',
      }));
      expect(await showData(chartTitles[1])).toHaveTextContent('555.00');
    }
    cleanup();
    vi.clearAllMocks();
    for (const state of ['history-only', 'no-funds', 'empty']) {
      cleanup();
      api.fetchVisualizations.mockResolvedValue({ ...figures,
        currentFiscalYearEnd: state === 'history-only' ? null : 2027,
        materialFiscalYearEnds: [], purchasesByMaterial: { fiscalYearEnd: null, items: [] },
        purchasesByCategory: state === 'empty' ? [] : figures.purchasesByCategory,
        budget: state === 'empty' ? [] : figures.budget,
      });
      renderPage();
      expect(await screen.findByLabelText('Material fiscal year')).toBeDisabled();
      expect(screen.getByText('No FOLIO purchase data for this unit')).toBeInTheDocument();
      if (state !== 'empty') expect(await showData(chartTitles[2])).toHaveTextContent('11,000.00');
    }
  });

  it('resets the material year to the new unit current fiscal year when the unit changes', async () => {
    {
      api.fetchVisualizations.mockImplementation(async (filters) => filters.acqUnitId === 'unit-ac' ? {
        ...figures, unit: options.units[1], currentFiscalYearEnd: 2025,
        materialFiscalYearEnds: [2024, 2025], purchasesByMaterial: { fiscalYearEnd: 2025, items: [{ materialType: 'Book', amount: 75 }] },
      } : figures);
      const user = userEvent.setup();
      renderPage();
      const year = await screen.findByLabelText('Material fiscal year');
      expect(year).toHaveValue('2027');
      await user.selectOptions(year, '2026');
      await user.selectOptions(screen.getByLabelText('Acquisitions unit'), 'unit-ac');
      await waitFor(() => expect(screen.getByLabelText('Material fiscal year')).toHaveValue('2025'));
      expect(api.fetchVisualizations.mock.calls.some(([filters]) => filters.acqUnitId === 'unit-ac' && filters.materialFiscalYearEnd === 2026)).toBe(false);
      expect(await showData(chartTitles[1])).toHaveTextContent('75.00');
    }
  });

  it('announces loading, explains empty results and recovers from errors with retry', async () => {
    {
      api.fetchVisualizations.mockReturnValue(new Promise(() => {}));
      const { container } = renderPage();
      await waitFor(() => {
        const announcement = container.querySelector('[aria-live]');
        expect(announcement).not.toBeNull();
        expect(announcement).toHaveTextContent(/loading/i);
      });
    }
    cleanup();
    vi.clearAllMocks();
    {
      api.fetchVisualizations.mockResolvedValue({ ...figures, fiscalYearEnds: [], materialFiscalYearEnds: [],
        purchasesByCategory: [], purchasesByMaterial: { fiscalYearEnd: null, items: [] }, budget: [],
      });
      renderPage();
      expect(await screen.findByText('No purchase or budget data for this unit in the selected years')).toBeInTheDocument();
    }
    cleanup();
    vi.clearAllMocks();
    {
      api.fetchVisualizations.mockRejectedValueOnce(new Error('Synthetic visualization failure')).mockResolvedValue(figures);
      renderPage();
      expect(await screen.findByRole('alert')).toHaveTextContent(/Synthetic visualization failure/);
      await userEvent.click(screen.getByRole('button', { name: /retry/i }));
      expect(await chart(chartTitles[0])).toBeInTheDocument();
      expect(screen.getByLabelText('Acquisitions unit')).toHaveValue('unit-sc');
    }
  });

  it.each(['material year', 'range'] as const)(
    'shows material loading instead of a confirmed empty result after changing the %s', async (selection) => {
      const { region } = await beginSelectionChange(selection);
      expect(within(region).getByRole('status')).toHaveTextContent('Loading material purchases…');
      expect(within(region).queryByText('No FOLIO purchases for this fiscal year')).not.toBeInTheDocument();
    },
  );

  it.each(['material year', 'range'] as const)(
    'shows the material request error and page retry instead of an empty result after changing the %s', async (selection) => {
      const { request, region } = await beginSelectionChange(selection);
      request.reject(new Error('Synthetic material request failure'));
      expect(await within(region).findByText(/Synthetic material request failure/)).toBeInTheDocument();
      expect(within(region).queryByText('No FOLIO purchases for this fiscal year')).not.toBeInTheDocument();
      expect(screen.getByRole('alert')).toHaveTextContent(/Synthetic material request failure/);
      expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
    },
  );

  it.each(['material year', 'range'] as const)(
    'shows the empty material result only after a successful empty response for the %s change', async (selection) => {
      const { request, region } = await beginSelectionChange(selection);
      request.resolve({ ...figures, purchasesByMaterial: {
        fiscalYearEnd: selection === 'material year' ? 2026 : 2027, items: [],
      } });
      expect(await within(region).findByText('No FOLIO purchases for this fiscal year')).toBeInTheDocument();
    },
  );

  it('supports admin history add, edit, delete and field errors while omitting the panel for non-admins', async () => {
    {
      auth.isAdmin = true;
      const added = { ...historyRow, id: 22, fiscalYearEnd: 2028, note: 'Added figure' };
      api.saveVisualizationHistory.mockResolvedValue(added);
      api.fetchVisualizationHistory.mockResolvedValueOnce([historyRow]).mockResolvedValue([historyRow, added]);
      renderPage();
      await screen.findByRole('region', { name: 'Historical figures' });
      await userEvent.click(within(historyEditor()).getByRole('button', { name: /add/i }));
      await fillHistoryForm('2028', '1000', 'Added figure');
      const callsBeforeSave = api.fetchVisualizations.mock.calls.length;
      await userEvent.click(within(historyEditor()).getByRole('button', { name: /save/i }));
      await waitFor(() => expect(api.saveVisualizationHistory).toHaveBeenCalledWith({
        acqUnitCode: 'SC', kind: 'purchases', fiscalYearEnd: 2028, totalAmount: 1000,
        electronicAmount: 600, physicalAmount: 300, note: 'Added figure',
      }));
      await waitFor(() => expect(api.fetchVisualizations.mock.calls.length).toBeGreaterThan(callsBeforeSave));
      expect(await within(historyEditor()).findByText('Added figure')).toBeInTheDocument();
    }
    cleanup();
    vi.clearAllMocks();
    {
      auth.isAdmin = true;
      api.fetchVisualizationHistory.mockResolvedValueOnce([historyRow]).mockResolvedValue([{ ...historyRow, totalAmount: 1200, note: 'Corrected figure' }]);
      api.saveVisualizationHistory.mockResolvedValue({ ...historyRow, totalAmount: 1200, note: 'Corrected figure' });
      renderPage();
      const panel = await screen.findByRole('region', { name: 'Historical figures' });
      const row = await within(panel).findByRole('row', { name: /2026.*purchases/i });
      expect(row).toHaveTextContent('7');
      expect(row).toHaveTextContent('2026-01-02');
      await userEvent.click(within(row).getByRole('button', { name: /edit/i }));
      expect(within(panel).getByLabelText('Total', { exact: true })).toHaveValue(1000);
      await fillHistoryForm('2026', '1200', 'Corrected figure');
      await userEvent.click(within(panel).getByRole('button', { name: /save/i }));
      await waitFor(() => expect(api.saveVisualizationHistory).toHaveBeenCalledWith({
        acqUnitCode: 'SC', kind: 'purchases', fiscalYearEnd: 2026, totalAmount: 1200,
        electronicAmount: 600, physicalAmount: 300, note: 'Corrected figure',
      }));
      expect(await within(panel).findByText('Corrected figure')).toBeInTheDocument();
    }
    cleanup();
    vi.clearAllMocks();
    {
      auth.isAdmin = true;
      api.fetchVisualizationHistory.mockResolvedValueOnce([historyRow]).mockResolvedValue([]);
      renderPage();
      const panel = await screen.findByRole('region', { name: 'Historical figures' });
      const row = await within(panel).findByRole('row', { name: /2026.*purchases/i });
      await userEvent.click(within(row).getByRole('button', { name: /delete/i }));
      const dialog = screen.queryByRole('dialog');
      if (dialog) await userEvent.click(within(dialog).getByRole('button', { name: /delete|confirm/i }));
      await waitFor(() => expect(api.deleteVisualizationHistory).toHaveBeenCalledWith(21));
      await waitFor(() => expect(within(panel).queryByRole('row', { name: /2026.*purchases/i })).not.toBeInTheDocument());
    }
    cleanup();
    vi.clearAllMocks();
    {
      auth.isAdmin = true;
      api.saveVisualizationHistory.mockRejectedValue({ response: { status: 422, data: { errors: {
        totalAmount: ['Synthetic total field error.'], note: ['Synthetic note field error.'],
      } } } });
      renderPage();
      const panel = await screen.findByRole('region', { name: 'Historical figures' });
      await userEvent.click(within(panel).getByRole('button', { name: /add/i }));
      await fillHistoryForm('2028', '1000', 'Entered note');
      await userEvent.click(within(panel).getByRole('button', { name: /save/i }));
      expect(await within(panel).findByText('Synthetic total field error.')).toBeInTheDocument();
      expect(within(panel).getByLabelText('Total', { exact: true })).toHaveAttribute('aria-invalid', 'true');
      expect(within(panel).getByLabelText('Total', { exact: true })).toHaveAccessibleDescription('Synthetic total field error.');
      expect(within(panel).getByLabelText('Note')).toHaveAccessibleDescription('Synthetic note field error.');
      expect(within(panel).getByLabelText('Total', { exact: true })).toHaveValue(1000);
      expect(within(panel).getByLabelText('Note')).toHaveValue('Entered note');
    }
    cleanup();
    vi.clearAllMocks();
    {
      auth.isAdmin = false;
      renderPage();
      expect(await chart(chartTitles[0])).toBeInTheDocument();
      expect(screen.queryByRole('region', { name: 'Historical figures' })).not.toBeInTheDocument();
      expect(api.fetchVisualizationHistory).not.toHaveBeenCalled();
    }
  });

  it('operates Show data toggles and the historical editor using the keyboard', async () => {
    {
      const user = userEvent.setup();
      renderPage();
      for (const title of chartTitles) {
        const region = await chart(title);
        const toggle = within(region).getByRole('button', { name: 'Show data' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        toggle.focus();
        await user.keyboard('{Enter}');
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(within(region).getByRole('table')).toBeVisible();
        await user.keyboard(' ');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(within(region).queryByRole('table')).not.toBeInTheDocument();
      }
    }
    cleanup();
    vi.clearAllMocks();
    {
      auth.isAdmin = true;
      const user = userEvent.setup();
      renderPage();
      const panel = await screen.findByRole('region', { name: 'Historical figures' });
      const row = await within(panel).findByRole('row', { name: /2026.*purchases/i });
      within(row).getByRole('button', { name: /edit/i }).focus();
      await user.keyboard('{Enter}');
      const total = within(panel).getByLabelText('Total', { exact: true });
      total.focus();
      await user.keyboard('{Control>}a{/Control}1200');
      expect(total).toHaveValue(1200);
      await user.tab();
      expect(panel).toContainElement(document.activeElement as HTMLElement);
      expect(document.activeElement).not.toBe(total);
      within(panel).getByRole('button', { name: /save/i }).focus();
      await user.keyboard('{Enter}');
      await waitFor(() => expect(api.saveVisualizationHistory).toHaveBeenCalledWith(expect.objectContaining({ totalAmount: 1200 })));
    }
  });

  it('keeps the material picker available after a material-year request fails', async () => {
    api.fetchVisualizations.mockResolvedValueOnce(figures)
      .mockRejectedValueOnce(new Error('Synthetic material request failure'))
      .mockResolvedValue(figures);
    const user = userEvent.setup();
    renderPage();
    const picker = await screen.findByLabelText('Material fiscal year');
    await user.selectOptions(picker, '2026');
    await screen.findByRole('alert');
    const pickerAfterError = screen.getByLabelText('Material fiscal year');
    expect(pickerAfterError).toBeEnabled();
    await user.selectOptions(pickerAfterError, '2027');
    await waitFor(() => expect(api.fetchVisualizations).toHaveBeenCalledTimes(3));
  });

  it('does not show prior material-year amounts after the selected year request fails', async () => {
    api.fetchVisualizations.mockResolvedValueOnce(figures)
      .mockRejectedValueOnce(new Error('Synthetic material-year failure'));
    const user = userEvent.setup();
    renderPage();
    const picker = await screen.findByLabelText('Material fiscal year');
    expect(picker).toHaveValue('2027');
    await user.selectOptions(picker, '2026');
    await screen.findByRole('alert');
    expect(screen.getByLabelText('Material fiscal year')).toHaveValue('2026');
    const table = await showData('How the library purchases by material');
    expect(table).not.toHaveTextContent('500.00');
    expect(table).not.toHaveTextContent('1,400.00');
    expect(within(table).getAllByRole('row')).toHaveLength(1);
  });

  it('keeps fiscal year and kind fixed when editing a stored history row', async () => {
    auth.isAdmin = true;
    const user = userEvent.setup();
    renderPage();
    const panel = await screen.findByRole('region', { name: 'Historical figures' });
    const row = await within(panel).findByRole('row', { name: /2026.*purchases/i });
    await user.click(within(row).getByRole('button', { name: /edit/i }));
    expect(within(panel).getByLabelText('Fiscal year')).toHaveAttribute('aria-readonly', 'true');
    expect(within(panel).getByLabelText('Kind')).toHaveAttribute('aria-readonly', 'true');
    await user.clear(within(panel).getByLabelText('Fiscal year'));
    await user.type(within(panel).getByLabelText('Fiscal year'), '2011');
    await user.selectOptions(within(panel).getByLabelText('Kind'), 'budget');
    await user.click(within(panel).getByRole('button', { name: /save/i }));
    await waitFor(() => expect(api.saveVisualizationHistory).toHaveBeenCalledWith(expect.objectContaining({
      fiscalYearEnd: 2026, kind: 'purchases',
    })));
  });

  it('renders null purchase percentages safely for a positive total', async () => {
    const nullableShares = {
      ...figures,
      purchasesByCategory: figures.purchasesByCategory.map((row) => row.fiscalYearEnd === 2027 ? {
        ...row, electronic: 0, physical: 0, other: 0, total: 0.01,
        electronicPct: null, physicalPct: null, otherPct: null,
      } : row),
    };
    api.fetchVisualizations.mockResolvedValue(nullableShares);
    renderPage();
    const table = await showData('How the library purchases');
    expect(within(table).getByRole('row', { name: /2027/ })).toHaveTextContent('n/a');
  });

  it('labels a missing material type explicitly in the material data table', async () => {
    api.fetchVisualizations.mockResolvedValue({
      ...figures,
      purchasesByMaterial: { fiscalYearEnd: 2027, items: [{ materialType: '', amount: 100 }] },
    });
    renderPage();
    const table = await showData('How the library purchases by material');
    expect(within(table).getByRole('row', { name: /No material type/ })).toBeInTheDocument();
  });

});
