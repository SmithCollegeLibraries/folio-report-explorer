import { Component, type ReactNode } from 'react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { VisualizationData, VisualizationHistoryRow, VisualizationOptions } from '../api/visualizations';
import { PurchaseCharts } from '../components/visualizations/PurchaseCharts';
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
  'Library budget',
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

async function renderWithData(data: Partial<VisualizationData>, waitForMaterialPie = true) {
  let resolveFigures!: () => void;
  const figuresLoaded = new Promise<void>((resolve) => { resolveFigures = resolve; });
  api.fetchVisualizations.mockImplementation(async () => {
    resolveFigures();
    return { ...figures, ...data };
  });
  renderPage();
  await waitFor(() => expect(api.fetchVisualizations).toHaveBeenCalled());
  await act(async () => { await figuresLoaded; });
  const purchaseRegion = await chart(chartTitles[0]);
  const materialRegion = await chart(chartTitles[1]);
  const budgetRegion = await chart(chartTitles[2]);
  await waitFor(() => {
    expect(purchaseRegion.querySelectorAll('svg .recharts-label-list text').length).toBeGreaterThan(0);
    if (waitForMaterialPie) expect(materialRegion.querySelectorAll('svg .recharts-pie-sector').length).toBeGreaterThan(0);
    expect(budgetRegion.querySelectorAll('svg .recharts-label-list text').length).toBeGreaterThan(0);
  });
}

function pieLabels(region: HTMLElement | null) {
  return Array.from(region?.querySelectorAll('svg .recharts-pie-labels text') ?? [])
    .map((label) => label.textContent?.replace(/\s+/g, ' ').trim() ?? '');
}

async function openTableWithoutAssumingItExists(region: HTMLElement | null) {
  const button = region?.querySelector('button[aria-controls]') as HTMLButtonElement | null;
  if (button) await userEvent.click(button);
  return region?.querySelector('table') ?? null;
}

function materialLegendColor(region: ParentNode | null, materialType: string) {
  const item = Array.from(region?.querySelectorAll('.recharts-legend-item') ?? [])
    .find((candidate) => candidate.querySelector('.recharts-legend-item-text')?.textContent?.trim() === materialType);
  return item?.querySelector('svg [fill]')?.getAttribute('fill') ?? null;
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

describe('Visualizations RPT-022 new behavior', () => {
  const chartTitlesForRestyle = {
    purchases: 'How the library purchases',
    material: 'How the library purchases by material',
    budget: 'Library budget',
  };
  const pieFixture = [
    { materialType: 'Hotel', amount: 40 },
    { materialType: 'Bravo', amount: 600 },
    { materialType: 'Zero', amount: 0 },
    { materialType: 'Alpha', amount: 700 },
    { materialType: 'Golf', amount: 60 },
    { materialType: 'Charlie', amount: 500 },
    { materialType: 'Foxtrot', amount: 200 },
    { materialType: 'Echo', amount: 300 },
    { materialType: 'Delta', amount: 400 },
    { materialType: 'Negative', amount: -14 },
  ];

  it('plots the six largest material types and their aggregated remainder with the right shares', async () => {
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items: pieFixture } });
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const labels = pieLabels(region).join(' | ');
    const sectors = region?.querySelectorAll('svg .recharts-pie-sector').length ?? 0;

    expect.soft(sectors).toBe(7);
    expect.soft(labels).toContain('Alpha');
    expect.soft(labels).toContain('25.0%');
    expect.soft(labels).toContain('Bravo');
    expect.soft(labels).toContain('21.4%');
    expect.soft(labels).toContain('All other types');
    expect.soft(labels).toContain('3.6%');
    const expectedShares = ['25.0%', '21.4%', '17.9%', '14.3%', '10.7%', '7.1%', '3.6%'];
    for (const share of expectedShares) expect.soft(labels).toContain(share);
    const renderedShares = pieLabels(region).flatMap((label) =>
      Array.from(label.matchAll(/(\d+\.\d)%/g), (match) => Number(match[1])));
    expect.soft(renderedShares).toHaveLength(7);
    expect.soft(renderedShares.reduce((total, share) => total + share, 0)).toBeCloseTo(100, 8);
  });

  it('orders pie slices by descending amount', async () => {
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items: pieFixture } });
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const renderedLabels = pieLabels(region);
    const order = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'All other types'];
    const observedOrder = renderedLabels.map((label) => order.find((name) => label.includes(name)))
      .filter((name): name is string => Boolean(name));

    expect.soft(observedOrder).toEqual(order);
  });

  it('draws a non-degenerate full circle for a fiscal year with one positive material', async () => {
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items: [{ materialType: 'Book', amount: 500 }] } });
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const sectors = Array.from(region?.querySelectorAll('svg .recharts-pie-sector') ?? []);
    const path = sectors[0];
    const arcCommands = (path?.getAttribute('d')?.match(/[Aa]/g) ?? []).length;

    expect.soft(sectors).toHaveLength(1);
    expect.soft(path?.tagName.toLowerCase()).toBe('path');
    expect.soft(arcCommands).toBeGreaterThanOrEqual(2);
  });

  it('uses material name to order equal-sized pie slices', async () => {
    const tiedItems = ['Zulu', 'Delta', 'Alpha', 'Mike', 'Beta', 'Gamma', 'Echo']
      .map((materialType) => ({ materialType, amount: 100 }));
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items: tiedItems } });
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const renderedLabels = pieLabels(region);
    const order = ['Alpha', 'Beta', 'Delta', 'Echo', 'Gamma', 'Mike', 'All other types'];
    const observedOrder = renderedLabels.map((label) => order.find((name) => label.includes(name)))
      .filter((name): name is string => Boolean(name));

    expect.soft(observedOrder).toEqual(order);
  });

  it('omits the aggregated remainder when there are six or fewer material types', async () => {
    const sixItems = pieFixture.filter((item) => item.amount > 0).slice(0, 6);
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items: sixItems } });
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const sectors = region?.querySelectorAll('svg .recharts-pie-sector').length ?? 0;

    expect.soft(sectors).toBe(6);
    expect.soft(region?.textContent ?? '').not.toContain('All other types');
  });

  it('keeps every material and its amount and percentage in the data table', async () => {
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items: pieFixture } });
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const table = await openTableWithoutAssumingItExists(region);
    const tableText = table?.textContent ?? '';
    const rows = Array.from(table?.querySelectorAll('tbody tr') ?? []);
    const rowText = (name: string) => rows.find((row) => row.textContent?.includes(name))?.textContent ?? '';

    expect.soft(table).not.toBeNull();
    expect.soft(rows).toHaveLength(pieFixture.length);
    for (const item of pieFixture) {
      expect.soft(tableText).toContain(item.materialType);
      expect.soft(rowText(item.materialType)).toContain(item.amount.toLocaleString('en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
      }));
      expect.soft(rowText(item.materialType)).toMatch(/-?\d+\.\d%/);
    }
    expect.soft(rowText('Alpha')).toContain('700.00');
    expect.soft(rowText('Alpha')).toContain('25.0%');
    expect.soft(rowText('Negative')).toContain('-14.00');
    expect.soft(rowText('Negative')).toContain('-0.5%');
  });

  it('does not draw zero or negative materials but keeps them in the data table', async () => {
    const items = [
      { materialType: 'Book', amount: 200 },
      { materialType: 'Database', amount: 100 },
      { materialType: 'No spend', amount: 0 },
      { materialType: 'Credit', amount: -14 },
    ];
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items } });
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const sectors = region?.querySelectorAll('svg .recharts-pie-sector').length ?? 0;
    const table = await openTableWithoutAssumingItExists(region);
    const tableText = table?.textContent ?? '';

    expect.soft(sectors).toBe(2);
    expect.soft(tableText).toContain('No spend');
    expect.soft(tableText).toContain('0.00');
    expect.soft(tableText).toContain('Credit');
    expect.soft(tableText).toContain('-14.00');
    expect.soft(tableText).toContain('-4.7%');
  });

  it('shows the empty-material status without a pie or legend and keeps every row in the table', async () => {
    const items = [
      { materialType: 'No spend', amount: 0 },
      { materialType: 'Credit', amount: -14 },
    ];
    await renderWithData({ purchasesByMaterial: { fiscalYearEnd: 2027, items } }, false);
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.material });
    const status = region?.querySelector('[role="status"]')?.textContent ?? '';
    const sectors = region?.querySelectorAll('svg .recharts-pie-sector') ?? [];
    const legend = region?.querySelectorAll('.recharts-legend-item') ?? [];
    const table = await openTableWithoutAssumingItExists(region);
    const rows = Array.from(table?.querySelectorAll('tbody tr') ?? []);
    const tableText = table?.textContent ?? '';

    expect.soft(status).toContain('No positive purchases for this fiscal year');
    expect.soft(sectors).toHaveLength(0);
    expect.soft(legend).toHaveLength(0);
    expect.soft(rows).toHaveLength(items.length);
    for (const item of items) {
      expect.soft(tableText).toContain(item.materialType);
      expect.soft(tableText).toContain(item.amount.toFixed(2));
    }
  });

  it('keeps a material type color stable when the selected fiscal year changes', async () => {
    const props = {
      materialYear: 2027 as number | 'all' | '', materialFiscalYearEnds: [2026, 2027],
      materialLoading: false, materialAvailable: true, onMaterialYear: vi.fn(),
    };
    const { container, rerender } = render(<PurchaseCharts data={{ ...figures,
      purchasesByMaterial: { fiscalYearEnd: 2027, items: [
        { materialType: 'Database', amount: 500 }, { materialType: 'Book', amount: 100 },
      ] },
    }} {...props} />);
    const regionFor2027 = Array.from(container.querySelectorAll('section[aria-labelledby]'))
      .find((section) => section.querySelector('h2')?.textContent === chartTitlesForRestyle.material) ?? null;
    await waitFor(() => expect(regionFor2027?.querySelectorAll('svg .recharts-pie-sector').length ?? 0).toBeGreaterThan(0));
    const colorFor2027 = materialLegendColor(regionFor2027, 'Database');

    rerender(<PurchaseCharts data={{ ...figures,
      purchasesByMaterial: { fiscalYearEnd: 2026, items: [
        { materialType: 'Book', amount: 300 }, { materialType: 'Database', amount: 100 },
      ] },
    }} {...props} materialYear={2026} />);
    const regionFor2026 = Array.from(container.querySelectorAll('section[aria-labelledby]'))
      .find((section) => section.querySelector('h2')?.textContent === chartTitlesForRestyle.material) ?? null;
    await waitFor(() => expect(regionFor2026?.querySelectorAll('svg .recharts-pie-sector').length ?? 0).toBeGreaterThan(0));
    const colorFor2026 = materialLegendColor(regionFor2026, 'Database');

    expect.soft(colorFor2027).not.toBeNull();
    expect.soft(colorFor2026).not.toBeNull();
    expect.soft(colorFor2026).toBe(colorFor2027);
  });

  it('shows one dollar-axis budget chart with the API change label on each bar', async () => {
    const budget = [
      { fiscalYearEnd: 2023, allocated: 4_080_000, changePct: 14.6, source: 'history' as const },
      { fiscalYearEnd: 2024, allocated: 4_000_000, changePct: null, source: 'folio' as const },
      { fiscalYearEnd: 2025, allocated: 3_000_000, changePct: 0, source: 'folio' as const },
      { fiscalYearEnd: 2026, allocated: 2_500_000, changePct: -12.7, source: 'folio' as const },
      { fiscalYearEnd: 2027, allocated: 2_250_000, changePct: 7.5, source: 'folio' as const },
    ];
    await renderWithData({ budget });
    const budgetRegions = screen.queryAllByRole('region').filter((region) =>
      /^Library budget/.test(region.querySelector('h2')?.textContent ?? ''));
    const budgetRegion = screen.queryByRole('region', { name: chartTitlesForRestyle.budget });
    const budgetLabels = Array.from(budgetRegion?.querySelectorAll('svg .recharts-label-list text') ?? []);
    const labelTexts = budgetLabels.map((label) => label.textContent?.replace(/\s+/g, ' ').trim() ?? '');
    const bars = Array.from(budgetRegion?.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle') ?? []);
    const labels = Array.from(budgetRegion?.querySelectorAll('svg text') ?? [])
      .map((label) => label.textContent?.replace(/\s+/g, ' ').trim() ?? '').join(' | ');
    const yAxes = budgetRegion?.querySelectorAll('svg .recharts-yAxis').length ?? 0;
    const yAxisLabels = Array.from(budgetRegion?.querySelectorAll('svg .recharts-yAxis .recharts-cartesian-axis-tick-value') ?? [])
      .map((label) => label.textContent ?? '');

    expect.soft(budgetRegions).toHaveLength(1);
    expect.soft(yAxes).toBe(1);
    expect.soft(yAxisLabels.some((label) => label.includes('%'))).toBe(false);
    expect.soft(labels).toContain('$4.08M');
    expect.soft(labels).toContain('▲ 14.6%');
    expect.soft(labels).toContain('n/a');
    expect.soft(labels).toMatch(/–\s*0\.0%/);
    expect.soft(labels).toContain('▼ 12.7%');
    expect.soft(budgetLabels).toHaveLength(budget.length);
    expect.soft(bars).toHaveLength(budget.length);
    const expectedLabelParts = [
      ['$4.08M', '▲ 14.6%'], ['$4.00M', 'n/a'], ['$3.00M', '– 0.0%'],
      ['$2.50M', '▼ 12.7%'], ['$2.25M', '▲ 7.5%'],
    ];
    labelTexts.forEach((label, index) => {
      for (const expected of expectedLabelParts[index] ?? []) expect.soft(label).toContain(expected);
    });
    bars.forEach((bar, index) => {
      const center = Number(bar.getAttribute('x')) + Number(bar.getAttribute('width')) / 2;
      const labelX = Number(budgetLabels[index]?.getAttribute('x'));
      expect.soft(Math.abs(labelX - center)).toBeLessThanOrEqual(1);
    });
  });

  it('keeps an amount label on every bar and omits change labels when twenty budget years are crowded', async () => {
    const budget = Array.from({ length: 20 }, (_, index) => ({
      fiscalYearEnd: 2008 + index,
      allocated: 4_080_000 - index * 11_000,
      changePct: index === 0 ? null : index % 2 === 0 ? 2.4 : -1.5,
      source: index % 5 === 0 ? 'history' as const : 'folio' as const,
    }));
    await renderWithData({ budget });
    const budgetRegion = screen.queryByRole('region', { name: chartTitlesForRestyle.budget });
    const bars = Array.from(budgetRegion?.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle') ?? []);
    const labels = Array.from(budgetRegion?.querySelectorAll('.recharts-label-list text') ?? []);
    const labelTexts = labels.map((label) => label.textContent?.replace(/\s+/g, ' ').trim() ?? '');
    const table = await openTableWithoutAssumingItExists(budgetRegion);
    const tableText = table?.textContent ?? '';

    expect.soft(bars).toHaveLength(budget.length);
    expect.soft(labels).toHaveLength(budget.length);
    expect.soft(labelTexts.every((label) => label.startsWith('$'))).toBe(true);
    expect.soft(labelTexts.some((label) => /[▲▼]|%/.test(label))).toBe(false);
    expect.soft(tableText).toContain('+2.4%');
    expect.soft(tableText).toContain('-1.5%');
  });

  it('renders the category share inside each purchase bar segment where it fits', async () => {
    await renderWithData({});
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.purchases });
    const labels = Array.from(region?.querySelectorAll('svg .recharts-label-list text') ?? [])
      .map((label) => label.textContent?.trim() ?? '');

    expect.soft(labels).toEqual(expect.arrayContaining(['60%', '30%', '70%', '25%']));
  });

  it('renders the group note with every material mapping from the backend', async () => {
    await renderWithData({});
    const region = screen.queryByRole('region', { name: chartTitlesForRestyle.purchases });
    const note = region?.textContent ?? '';
    const backendSource = readFileSync(resolve(process.cwd(), '../backend/services/VisualizationDataService.php'), 'utf8');
    const parseBackendGroup = (group: string) => {
      const match = backendSource.match(new RegExp(`private const ${group} = \\[([^\\]]*)\\];`));
      return match ? Array.from(match[1].matchAll(/'([^']*)'/g), (item) => item[1]) : null;
    };
    const noteLines = Array.from(region?.querySelectorAll('aside p') ?? []);
    const readRenderedGroup = (group: string) => {
      const line = noteLines.find((paragraph) => paragraph.querySelector('strong')?.textContent?.replace(':', '').trim() === group);
      return line?.textContent?.replace(new RegExp(`^${group}:\\s*`), '').split(', ').map((item) => item.trim()) ?? null;
    };

    expect.soft(note).toContain("What's in each group");
    for (const material of [
      'Data File', 'Database', 'E-Book', 'E-Book Package', 'E-Journal', 'E-Journal Package', 'E-Newspaper', 'Streaming Video',
      'Audio CD', 'Book', 'DVD/Blu-ray', 'Journal', 'Newspaper', 'Score', 'Serial',
    ]) expect.soft(note).toContain(material);
    expect.soft(note).toMatch(/Other.*every other material type.*purchases without one/i);
    const electronic = readRenderedGroup('Electronic');
    const physical = readRenderedGroup('Physical');
    const backendElectronic = parseBackendGroup('ELECTRONIC');
    const backendPhysical = parseBackendGroup('PHYSICAL');
    expect.soft(backendElectronic).not.toBeNull();
    expect.soft(backendPhysical).not.toBeNull();
    expect.soft(electronic).toEqual(backendElectronic);
    expect.soft(physical).toEqual(backendPhysical);
  });

  it('keeps the rendered group note mapping in parity with both backend category lists', () => {
    const frontendSource = readFileSync(resolve(process.cwd(), 'src/components/visualizations/PurchaseCharts.tsx'), 'utf8');
    const backendSource = readFileSync(resolve(process.cwd(), '../backend/services/VisualizationDataService.php'), 'utf8');
    const declaration = frontendSource.match(/export const PURCHASE_MATERIAL_GROUPS\s*=\s*\{([\s\S]*?)\}\s*(?:as const)?;/);
    const parseFrontendGroup = (group: string) => {
      const match = declaration?.[1].match(new RegExp(`${group}\\s*:\\s*\\[([^\\]]*)\\]`));
      return match ? Array.from(match[1].matchAll(/(['"])(.*?)\1/g), (item) => item[2]) : null;
    };
    const parseBackendGroup = (group: string) => {
      const match = backendSource.match(new RegExp(`private const ${group} = \\[([^\\]]*)\\];`));
      return match ? Array.from(match[1].matchAll(/'([^']*)'/g), (item) => item[1]) : null;
    };
    const frontendGroups = {
      electronic: parseFrontendGroup('electronic'),
      physical: parseFrontendGroup('physical'),
    };
    const backendGroups = {
      electronic: parseBackendGroup('ELECTRONIC'),
      physical: parseBackendGroup('PHYSICAL'),
    };

    expect.soft(frontendGroups).toEqual(backendGroups);
    expect.soft((frontendSource.match(/PURCHASE_MATERIAL_GROUPS/g) ?? []).length).toBeGreaterThan(1);
  });

});

afterEach(() => {
  cleanup();
  window.removeEventListener('error', suppressStubError);
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('Visualizations RPT-018 behavior', () => {
  it('renders the three charts from synthetic purchase and budget fixtures', async () => {
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
      for (const title of [chartTitles[0], chartTitles[2]]) {
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
      expect(within(budget).getByRole('row', { name: /2025/ })).toHaveTextContent('n/a');
      expect(within(budget).getByRole('row', { name: /2026/ })).toHaveTextContent(/[-−]20\.0%/);
      expect(within(budget).getByRole('row', { name: /2027/ })).toHaveTextContent('+10.0%');
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
