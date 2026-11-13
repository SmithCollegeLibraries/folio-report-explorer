import { Component, type ReactNode } from 'react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation, useNavigationType } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { VisualizationData, VisualizationHistoryRow, VisualizationOptions } from '../api/visualizations';
import { PurchaseCharts } from '../components/visualizations/PurchaseCharts';
import { SpendingBudgetChart } from '../components/visualizations/SpendingBudgetChart';
import Visualizations from './Visualizations';

const api = vi.hoisted(() => ({
  fetchVisualizationOptions: vi.fn(),
  fetchVisualizations: vi.fn(),
  fetchVisualizationHistory: vi.fn(),
  saveVisualizationHistory: vi.fn(),
  deleteVisualizationHistory: vi.fn(),
}));
const gateCountsApi = vi.hoisted(() => ({ fetchGateCounts: vi.fn() }));
const auth = vi.hoisted(() => ({ isAdmin: false }));
vi.mock('../api/visualizations', () => api);
vi.mock('../api/gateCounts', () => gateCountsApi);
vi.mock('../hooks/useAuth', () => ({ useAuth: () => auth }));

function gateCountFixture(visits = 11) {
  return {
    doors: ['North Door', 'South Door'],
    period: { start: '2025-11-02', end: '2025-11-05' },
    monthlyByFiscalYear: [{
      fiscalYearEnd: 2026,
      months: [
        { month: '2025-07', visits: visits === 0 ? 0 : 4 }, { month: '2025-08', visits: 0 },
        { month: '2025-09', visits: 0 }, { month: '2025-10', visits: 0 },
        { month: '2025-11', visits: visits },
      ],
    }],
    daily: [
      { date: '2025-11-02', visits: visits }, { date: '2025-11-03', visits: visits === 0 ? 0 : 7 },
      { date: '2025-11-04', visits: visits === 0 ? 0 : 7 }, { date: '2025-11-05', visits: 0 },
    ],
    heatmap: Array.from({ length: 168 }, (_, index) => ({
      weekday: Math.floor(index / 24), hour: index % 24, visits: index === 1 ? visits : 0,
    })),
    topDays: visits === 0 ? [] : [
      { date: '2025-11-02', visits: visits },
      { date: '2025-11-03', visits: 7 },
      { date: '2025-11-04', visits: 7 },
    ],
  };
}

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
const expectedPurchaseMaterialGroups = {
  electronic: [
    'Data File', 'Database', 'E-Book', 'E-Book Package', 'E-Journal', 'E-Journal Package',
    'E-Newspaper', 'Streaming Video', 'E-Score', 'Streaming Audio', 'E-Thesis/Dissertation',
  ],
  physical: [
    'Audio CD', 'Book', 'DVD/Blu-ray', 'Journal', 'Newspaper', 'Score', 'Serial', 'Map', 'Microform',
    'Analog Game', 'Archival material', 'Artifact/Object', 'Audiocassette', 'CD-ROM', 'Equipment', 'Film',
    'Government Publication', 'Image', 'LP Phonorecord', 'Musical Instrument', 'Supplement',
    'Thesis/Dissertation', 'Videocassette', 'Video Game',
  ],
} as const;

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

function RouterLocation() {
  const location = useLocation();
  const navigationType = useNavigationType();
  return <output data-testid="router-location" data-navigation={navigationType}>{location.pathname}{location.search}</output>;
}

function renderPage(options: { initialEntry?: string; basename?: string } = {}) {
  const client = new QueryClient({ defaultOptions: {
    queries: { retry: false }, mutations: { retry: false },
  } });
  return render(<QueryClientProvider client={client}><MemoryRouter basename={options.basename}
    initialEntries={[options.initialEntry ?? '/visualizations']}
    future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
    <RouterLocation /><StubBoundary><Visualizations /></StubBoundary>
  </MemoryRouter></QueryClientProvider>);
}

async function selectTab(name: string) {
  const tab = screen.getByRole('tab', { name });
  if (tab.getAttribute('aria-selected') !== 'true') await userEvent.click(tab);
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

async function openChartIfAvailable(title: string) {
  const region = await chart(title);
  const enlarge = within(region).queryByRole('button', { name: 'Enlarge' });
  if (enlarge) await userEvent.click(enlarge);
  return { region, enlarge, dialog: screen.queryByRole('dialog') };
}

function setFullscreenApi(available: boolean) {
  const fullscreenEnabled = Object.getOwnPropertyDescriptor(document, 'fullscreenEnabled');
  const requestFullscreenDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'requestFullscreen');
  const requestFullscreen = vi.fn(async function (this: HTMLElement) {});
  Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, value: available });
  if (available) {
    Object.defineProperty(HTMLElement.prototype, 'requestFullscreen', {
      configurable: true, value: requestFullscreen,
    });
  } else {
    Reflect.deleteProperty(HTMLElement.prototype, 'requestFullscreen');
  }
  return {
    requestFullscreen,
    restore() {
      if (fullscreenEnabled) Object.defineProperty(document, 'fullscreenEnabled', fullscreenEnabled);
      else Reflect.deleteProperty(document, 'fullscreenEnabled');
      if (requestFullscreenDescriptor) {
        Object.defineProperty(HTMLElement.prototype, 'requestFullscreen', requestFullscreenDescriptor);
      } else {
        Reflect.deleteProperty(HTMLElement.prototype, 'requestFullscreen');
      }
    },
  };
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

async function openHistoryEditor() {
  await userEvent.click(await screen.findByRole('button', { name: 'Manage historical figures' }));
  return screen.findByRole('region', { name: 'Historical figures' });
}

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
  gateCountsApi.fetchGateCounts.mockResolvedValue(gateCountFixture());
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

  it('explains the displayed material slices and repeats the note in the enlarged chart', async () => {
    const items = [
      { materialType: 'Admin', amount: 2500 },
      { materialType: 'Database', amount: 2000 },
      { materialType: 'Book', amount: 1500 },
      { materialType: 'E-Journal', amount: 1200 },
      { materialType: 'E-Book', amount: 1000 },
      { materialType: 'Journal', amount: 800 },
      { materialType: 'E-Newspaper', amount: 600 },
      { materialType: 'Streaming Video', amount: 400 },
      { materialType: 'No purchases', amount: 0 },
      { materialType: 'Credit', amount: -14 },
    ];
    const { container } = render(<PurchaseCharts data={{ ...figures,
      purchasesByMaterial: { fiscalYearEnd: 2026, items },
    }} materialYear={2026} materialFiscalYearEnds={[2026]} materialLoading={false}
      materialAvailable onMaterialYear={vi.fn()} />);
    const region = Array.from(container.querySelectorAll('section[aria-labelledby]'))
      .find((section) => section.querySelector('h2')?.textContent === chartTitlesForRestyle.material);

    await waitFor(() => expect(region?.querySelectorAll('svg .recharts-pie-sector').length).toBe(7));
    const note = region?.querySelector('aside');
    const lines = Array.from(note?.querySelectorAll('p') ?? []);
    expect(note).not.toBeNull();
    expect(note?.querySelector('h3')?.textContent).toBe("What's in each slice");
    expect(lines.map((line) => line.querySelector('strong')?.textContent)).toEqual([
      'Admin: 2,500.00 (25.0%)', 'Database: 2,000.00 (20.0%)', 'Book: 1,500.00 (15.0%)',
      'E-Journal: 1,200.00 (12.0%)', 'E-Book: 1,000.00 (10.0%)', 'Journal: 800.00 (8.0%)',
      'All other types: 1,000.00 (10.0%)',
    ]);
    expect(lines[0]).toHaveTextContent('Library services, not materials: cataloging and metadata services (OCLC)');
    expect(lines[6]).toHaveTextContent('E-Newspaper: 600.00, Streaming Video: 400.00');
    expect(note?.textContent).not.toContain('No purchases');
    expect(note?.textContent).not.toContain('Credit');

    await userEvent.click(within(region as HTMLElement).getByRole('button', { name: 'Enlarge' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('complementary').textContent).toBe(note?.textContent);
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

  it('renders sparse spending and allocation values with separate provenance and readable small purchases', async () => {
    const purchases: VisualizationData['purchasesByCategory'] = [
      { fiscalYearEnd: 2024, electronic: 100, physical: 0, other: -10, total: 90,
        electronicPct: 111.1, physicalPct: 0, otherPct: -11.1, source: 'history' },
      { fiscalYearEnd: 2026, electronic: 10, physical: 0, other: 0, total: 10,
        electronicPct: 100, physicalPct: 0, otherPct: 0, source: 'folio' },
      { fiscalYearEnd: 2030, electronic: 100, physical: 0, other: 0, total: 100,
        electronicPct: 100, physicalPct: 0, otherPct: 0, source: 'history' },
    ];
    const budget: VisualizationData['budget'] = [
      { fiscalYearEnd: 2025, allocated: 0, changePct: null, source: 'history' },
      { fiscalYearEnd: 2027, allocated: -50, changePct: null, source: 'folio' },
      { fiscalYearEnd: 2029, allocated: 100, changePct: null, source: 'history' },
      { fiscalYearEnd: 2030, allocated: 1_000_000, changePct: null, source: 'folio' },
    ];
    await renderWithData({ purchasesByCategory: purchases, budget });

    const region = await chart('Spending vs budget');
    await waitFor(() => expect(region.querySelectorAll('.recharts-line-dots circle')).toHaveLength(4));
    const allocationDots = Array.from(region.querySelectorAll('.recharts-line-dots circle'));
    expect((region.querySelector('.recharts-line-curve')?.getAttribute('d')?.match(/M/g) ?? []).length).toBeGreaterThan(1);
    expect(allocationDots[0]?.getAttribute('fill')).toBe('white');
    expect(allocationDots[1]?.getAttribute('fill')).toBe('#334155');
    expect(allocationDots[2]?.getAttribute('fill')).toBe('white');
    const bars = Array.from(region.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle'));
    expect(bars.length).toBeGreaterThan(0);
    expect(bars.some((bar) => (bar.getAttribute('fill') ?? '').startsWith('url(#'))).toBe(true);
    expect(region.querySelectorAll('.recharts-yAxis')).toHaveLength(1);
    expect(region).toHaveTextContent('Hollow dot: stored history allocation');

    const showDataButton = within(region).getByRole('button', { name: 'Show data' });
    showDataButton.focus();
    await userEvent.keyboard('{Enter}');
    const table = within(region).getByRole('table');
    const row = (year: number) => Array.from(table.querySelectorAll('tbody tr'))
      .find((candidate) => candidate.querySelector('th')?.textContent === String(year));
    const mixedSource = row(2030);
    expect(table.querySelector('caption')).toHaveTextContent('Spending vs budget');
    expect(Array.from(table.querySelectorAll('thead th')).map((cell) => cell.textContent)).toEqual([
      'Fiscal year', 'Electronic', 'Physical', 'Other', 'Total paid', 'Allocated',
      'Paid as % of allocated', 'Purchases source', 'Allocation source',
    ]);
    expect(row(2024)).toHaveTextContent('0.00');
    expect(row(2024)).toHaveTextContent('-10.00');
    expect(row(2024)?.children[5]).toHaveTextContent('n/a');
    expect(row(2024)?.children[6]).toHaveTextContent('n/a');
    expect(row(2025)?.children[1]).toHaveTextContent('n/a');
    expect(row(2025)?.children[5]).toHaveTextContent('0.00');
    expect(row(2025)?.children[6]).toHaveTextContent('n/a');
    expect(row(2027)?.children[5]).toHaveTextContent('-50.00');
    expect(row(2027)?.children[6]).toHaveTextContent('n/a');
    expect(row(2029)?.children[6]).toHaveTextContent('n/a');
    expect(mixedSource?.children[4]).toHaveTextContent('100.00');
    expect(mixedSource?.children[5]).toHaveTextContent('1,000,000.00');
    expect(mixedSource?.children[6]).toHaveTextContent('0.0%');
    expect(mixedSource?.children[7]).toHaveTextContent('Stored history');
    expect(mixedSource?.children[8]).toHaveTextContent('FOLIO');

    const chartWrapper = region.querySelector('.recharts-wrapper');
    const lastDot = region.querySelector('.recharts-line-dots circle:last-child');
    if (chartWrapper && lastDot) {
      Object.defineProperties(chartWrapper, {
        offsetWidth: { configurable: true, value: 800 },
        offsetHeight: { configurable: true, value: 320 },
      });
      fireEvent.mouseMove(chartWrapper, {
        clientX: Number(lastDot.getAttribute('cx')), clientY: 100,
      });
    }
    expect(await within(region).findByText('Purchases source: Stored history')).toBeInTheDocument();
    expect(region).toHaveTextContent('Electronic: 100.00');
    expect(region).toHaveTextContent('Allocated: 1,000,000.00');
    expect(region).toHaveTextContent('Allocation source: FOLIO');

    const enlarge = within(region).getByRole('button', { name: 'Enlarge' });
    await userEvent.click(enlarge);
    const dialog = await screen.findByRole('dialog', { name: 'Spending vs budget' });
    expect(dialog).toHaveTextContent('Hollow dot: stored history allocation');
    expect(dialog).toHaveTextContent('Use the tooltip or table for small values.');
    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(document.activeElement).toBe(enlarge);
  });

  it('includes zero on the spending dollar axis when all values are positive', async () => {
    const data: VisualizationData = {
      ...figures,
      purchasesByCategory: [],
      budget: [
        { fiscalYearEnd: 2026, allocated: 500, changePct: null, source: 'folio' },
        { fiscalYearEnd: 2027, allocated: 1000, changePct: null, source: 'folio' },
      ],
    };
    const { container } = render(<SpendingBudgetChart data={data} />);
    await waitFor(() => expect(container.querySelectorAll('.recharts-line-dots circle')).toHaveLength(2));
    const zeroY = Number(container.querySelector('.recharts-reference-line-line')?.getAttribute('y1'));
    const firstAllocationY = Number(container.querySelector('.recharts-line-dots circle')?.getAttribute('cy'));
    expect(firstAllocationY).toBeLessThan(zeroY);
  });

  it('renders negative purchase categories below the zero baseline', async () => {
    const data: VisualizationData = {
      ...figures,
      purchasesByCategory: [{ fiscalYearEnd: 2026, electronic: 20, physical: 10, other: -50, total: -20,
        electronicPct: -100, physicalPct: -50, otherPct: 250, source: 'folio' }],
      budget: [{ fiscalYearEnd: 2026, allocated: 100, changePct: null, source: 'folio' }],
    };
    const { container } = render(<SpendingBudgetChart data={data} />);
    await waitFor(() => expect(container.querySelector('.recharts-reference-line-line')).not.toBeNull());
    const zeroY = Number(container.querySelector('.recharts-reference-line-line')?.getAttribute('y1'));
    const negativeBar = container.querySelector('.recharts-rectangle[name="Other"]');
    const negativeY = Number(negativeBar?.getAttribute('y'));
    const negativeHeight = Number(negativeBar?.getAttribute('height'));
    expect(negativeY).toBeGreaterThan(zeroY);
    expect(negativeY + negativeHeight).toBeCloseTo(zeroY, 3);
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

  it('renders the complete material mappings and explains Other and historical splits', async () => {
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
    const otherLine = noteLines.find((paragraph) => paragraph.querySelector('strong')?.textContent?.replace(':', '').trim() === 'Other')?.textContent ?? '';
    for (const material of [...expectedPurchaseMaterialGroups.electronic, ...expectedPurchaseMaterialGroups.physical]) {
      expect.soft(note).toContain(material);
    }
    expect.soft(readRenderedGroup('Electronic')).toEqual(expectedPurchaseMaterialGroups.electronic);
    expect.soft(readRenderedGroup('Physical')).toEqual(expectedPurchaseMaterialGroups.physical);
    expect.soft(otherLine).toMatch(/Other:.*collection support/i);
    expect.soft(otherLine).toContain('SC E-Collection Support (SCECS)');
    expect.soft(otherLine).toContain('SC P-Collection Support (SCPCS)');
    expect.soft(otherLine).toContain('SC Interlibrary Loan (SCILL)');
    expect.soft(otherLine).toMatch(/payments without a usable material type/i);
    expect.soft(note).toMatch(/Pre-FOLIO history years keep their recorded split/i);
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

    expect.soft(frontendGroups).toEqual(expectedPurchaseMaterialGroups);
    expect.soft(frontendGroups).toEqual(backendGroups);
    expect.soft((frontendSource.match(/PURCHASE_MATERIAL_GROUPS/g) ?? []).length).toBeGreaterThan(1);
  });

});

describe('Visualizations RPT-034 gate counts', () => {
  it('mounts all four accessible charts with independent controls when acquisitions fail', async () => {
    api.fetchVisualizations.mockRejectedValue(new Error('Synthetic acquisitions failure'));
    renderPage();
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic acquisitions failure');
    expect(screen.getByLabelText('Years')).toBeInTheDocument();
    await selectTab('Gate counts');

    const section = await screen.findByRole('region', { name: 'Gate counts' });
    expect(await within(section).findByText('Entries at North Door, South Door, from SenSource Vea, Eastern time.')).toBeInTheDocument();
    expect(screen.getByLabelText('Start date')).toHaveValue('2025-11-02');
    expect(screen.getByLabelText('End date')).toHaveValue('2025-11-05');
    expect(screen.getByLabelText('Gate count years')).toHaveValue('3');
    expect(screen.getByLabelText('Years')).toBeInTheDocument();

    for (const title of ['Monthly visits by fiscal year', 'Busiest hours (average per day)', 'Daily visits', 'Top 10 busiest days']) {
      expect(within(section).getByRole('region', { name: title })).toBeInTheDocument();
    }
    expect(within(section).getByRole('button', {
      name: 'Sunday 1:00-2:00: about 11 entries on an average Sunday (11 over 1 Sunday)',
    })).toBeInTheDocument();
    const busiestDays = within(section).getByRole('region', { name: 'Top 10 busiest days' });
    await userEvent.click(within(busiestDays).getByRole('button', { name: 'Show data' }));
    expect(within(busiestDays).getByRole('table')).toHaveTextContent('Nov 2, 2025');
    const monthly = within(section).getByRole('region', { name: 'Monthly visits by fiscal year' });
    await userEvent.click(within(monthly).getByRole('button', { name: 'Show data' }));
    expect(within(monthly).getByRole('table')).toHaveTextContent('July');
    expect(gateCountsApi.fetchGateCounts).toHaveBeenCalledWith({ years: 3 });

    await userEvent.selectOptions(screen.getByLabelText('Gate count years'), '2');
    await waitFor(() => expect(gateCountsApi.fetchGateCounts).toHaveBeenLastCalledWith({ years: 2 }));
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2025-11-03' } });
    await waitFor(() => expect(gateCountsApi.fetchGateCounts).toHaveBeenLastCalledWith({
      years: 2, start: '2025-11-03', end: '2025-11-05',
    }));
  });

  it('shows loading and a retryable error inside the gate-count section', async () => {
    let rejectRequest!: (error: Error) => void;
    gateCountsApi.fetchGateCounts.mockImplementationOnce(() => new Promise((_resolve, reject) => {
      rejectRequest = reject;
    }));
    renderPage();
    await selectTab('Gate counts');
    const section = await screen.findByRole('region', { name: 'Gate counts' });
    expect(within(section).getByRole('status')).toHaveTextContent('Loading gate counts…');

    await act(async () => { rejectRequest(new Error('Synthetic Vea failure')); });
    expect(await within(section).findByRole('alert')).toHaveTextContent('Could not load gate counts.');
    await selectTab('Acquisitions');
    expect(await screen.findByRole('region', { name: 'How the library purchases' })).toBeInTheDocument();
    await selectTab('Gate counts');
    gateCountsApi.fetchGateCounts.mockResolvedValueOnce(gateCountFixture());
    await userEvent.click(within(section).getByRole('button', { name: 'Retry gate counts' }));
    expect(await within(section).findByRole('region', { name: 'Monthly visits by fiscal year' })).toBeInTheDocument();
  });

  it('shows the empty state when every gate-count series has zero visits', async () => {
    gateCountsApi.fetchGateCounts.mockResolvedValue(gateCountFixture(0));
    renderPage();
    await selectTab('Gate counts');
    const section = await screen.findByRole('region', { name: 'Gate counts' });
    expect(await within(section).findByText('No gate counts for this period.')).toBeInTheDocument();
  });

  it('shows average weekday visits in the heatmap, tooltip and table using period dates', async () => {
    const heatmap = Array.from({ length: 168 }, (_, index) => ({
      weekday: Math.floor(index / 24), hour: index % 24, visits: 0,
    }));
    for (const [weekday, hour, visits] of [[0, 12, 1], [0, 13, 1], [1, 12, 1], [2, 12, 1], [3, 12, 1]]) {
      heatmap[weekday * 24 + hour].visits = visits;
    }
    gateCountsApi.fetchGateCounts.mockResolvedValue({
      ...gateCountFixture(),
      period: { start: '2025-11-02', end: '2025-11-17' },
      heatmap,
    });
    renderPage({ initialEntry: '/visualizations?tab=gate-counts' });

    const busiestHours = await screen.findByRole('region', { name: 'Busiest hours (average per day)' });
    expect(within(busiestHours).getByText('Average entries per day by Eastern weekday and hour, over the selected period.')).toBeInTheDocument();
    expect(within(busiestHours).getByText('More entries')).toBeInTheDocument();
    expect(within(busiestHours).getByRole('button', {
      name: 'Tuesday 12:00-13:00: about 1 entry on an average Tuesday (1 over 2 Tuesdays)',
    })).toBeInTheDocument();

    const tuesdayNoon = within(busiestHours).getByRole('button', { name: /Tuesday 12:00-13:00/ });
    const opacityOf = (button: HTMLElement) => button.style.backgroundColor.startsWith('rgba')
      ? Number(button.style.backgroundColor.match(/, ([\d.]+)\)$/)?.[1]) : 1;
    expect(opacityOf(tuesdayNoon)).toBe(1);
    const mondayNoon = within(busiestHours).getByRole('button', { name: /Monday 12:00-13:00/ });
    expect(opacityOf(mondayNoon)).toBeCloseTo(0.15 + ((1 / 3) / 0.5) * 0.85);

    const table = await showData('Busiest hours (average per day)');
    expect(table.querySelector('caption')?.textContent).toBe('Busiest hours (average entries per day)');
    expect(within(table).getByText('Days in period: Sunday: 3, Monday: 3, Tuesday: 2, Wednesday: 2, Thursday: 2, Friday: 2, Saturday: 2.')).toBeInTheDocument();
    const row = (weekday: string) => within(table).getByRole('rowheader', { name: weekday }).closest('tr') as HTMLElement;
    const monday = row('Monday');
    const tuesday = row('Tuesday');
    const wednesday = row('Wednesday');
    const sunday = row('Sunday');
    expect(within(monday).getAllByRole('cell')[12]).toHaveTextContent('<1');
    expect(within(tuesday).getAllByRole('cell')[12]).toHaveTextContent('1');
    expect(within(wednesday).getAllByRole('cell')[12]).toHaveTextContent('1');
    expect(within(sunday).getAllByRole('cell')[13]).toHaveTextContent('<1');
  });

  it('shows no days for weekdays outside a short selected period', async () => {
    gateCountsApi.fetchGateCounts.mockResolvedValue({
      ...gateCountFixture(),
      period: { start: '2025-11-03', end: '2025-11-04' },
      heatmap: Array.from({ length: 168 }, (_, index) => ({
        weekday: Math.floor(index / 24), hour: index % 24, visits: 0,
      })),
    });
    renderPage({ initialEntry: '/visualizations?tab=gate-counts' });

    const busiestHours = await chart('Busiest hours (average per day)');
    expect(within(busiestHours).getByRole('button', { name: 'Sunday 12:00-13:00: no days' })).toBeInTheDocument();
    const table = await showData('Busiest hours (average per day)');
    const sunday = within(table).getByRole('rowheader', { name: 'Sunday' }).closest('tr') as HTMLElement;
    expect(within(sunday).getAllByRole('cell')[12]).toHaveTextContent('no days');
  });
});

describe('Visualizations RPT-037 tabs and top days', () => {
  it('provides URL-backed ARIA tabs with keyboard navigation and an acquisitions fallback', async () => {
    renderPage({ initialEntry: '/fre/visualizations?tab=unknown', basename: '/fre' });

    const tablist = screen.getByRole('tablist');
    const acquisitions = within(tablist).getByRole('tab', { name: 'Acquisitions' });
    const gateCounts = within(tablist).getByRole('tab', { name: 'Gate counts' });
    expect(acquisitions).toHaveAttribute('aria-selected', 'true');
    expect(gateCounts).toHaveAttribute('aria-selected', 'false');
    expect(screen.getByRole('tabpanel', { name: 'Acquisitions' })).toBeInTheDocument();
    expect(gateCountsApi.fetchGateCounts).not.toHaveBeenCalled();

    acquisitions.focus();
    fireEvent.keyDown(acquisitions, { key: 'ArrowRight' });
    expect(gateCounts).toHaveFocus();
    expect(gateCounts).toHaveAttribute('aria-selected', 'true');
    expect(gateCounts).toHaveClass('focus-visible:outline');
    expect(screen.getByTestId('router-location')).toHaveTextContent('/visualizations?tab=gate-counts');
    expect(screen.getByTestId('router-location')).toHaveAttribute('data-navigation', 'REPLACE');
    expect(await screen.findByRole('tabpanel', { name: 'Gate counts' })).toBeInTheDocument();
    expect(gateCountsApi.fetchGateCounts).toHaveBeenCalled();

    fireEvent.keyDown(gateCounts, { key: 'ArrowLeft' });
    expect(acquisitions).toHaveFocus();
    expect(screen.getByTestId('router-location')).toHaveTextContent('/visualizations?tab=acquisitions');
    fireEvent.keyDown(acquisitions, { key: 'End' });
    expect(gateCounts).toHaveFocus();
    fireEvent.keyDown(gateCounts, { key: 'Home' });
    expect(acquisitions).toHaveFocus();
  });

  it('opens a gate-count link without fetching acquisitions and retains each group’s controls', async () => {
    renderPage({ initialEntry: '/fre/visualizations?tab=gate-counts', basename: '/fre' });
    const gateSection = await screen.findByRole('region', { name: 'Gate counts' });
    expect(within(gateSection).getByLabelText('Gate count years')).toHaveValue('3');
    expect(gateCountsApi.fetchGateCounts).toHaveBeenCalledWith({ years: 3 });
    expect(api.fetchVisualizationOptions).not.toHaveBeenCalled();
    expect(api.fetchVisualizations).not.toHaveBeenCalled();

    await userEvent.selectOptions(within(gateSection).getByLabelText('Gate count years'), '2');
    await waitFor(() => expect(gateCountsApi.fetchGateCounts).toHaveBeenLastCalledWith({ years: 2 }));
    await selectTab('Acquisitions');
    await waitFor(() => expect(api.fetchVisualizations).toHaveBeenCalled());
    await userEvent.selectOptions(screen.getByLabelText('Years'), '5');
    await waitFor(() => expect(api.fetchVisualizations).toHaveBeenLastCalledWith({ acqUnitId: 'unit-sc', years: 5 }));

    await selectTab('Gate counts');
    expect(within(gateSection).getByLabelText('Gate count years')).toHaveValue('2');
    await selectTab('Acquisitions');
    expect(screen.getByLabelText('Years')).toHaveValue('5');
  });

  it.each([
    { days: gateCountFixture().topDays, expectedBars: 3, expectedLabels: ['Sun, Nov 2, 2025', 'Mon, Nov 3, 2025', 'Tue, Nov 4, 2025'] },
    { days: [], expectedBars: 0, expectedLabels: [] },
  ])('renders the busiest-days chart and ranked table for $expectedBars days', async ({ days, expectedBars, expectedLabels }) => {
    gateCountsApi.fetchGateCounts.mockResolvedValue({ ...gateCountFixture(), topDays: days });
    renderPage();
    await selectTab('Gate counts');

    const busiestDays = await screen.findByRole('region', { name: 'Top 10 busiest days' });
    if (expectedBars === 0) {
      expect(within(busiestDays).getByRole('status')).toHaveTextContent('No days with visits in this period.');
    } else {
      await waitFor(() => expect(busiestDays.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle'))
        .toHaveLength(expectedBars));
      const labels = Array.from(busiestDays.querySelectorAll('.recharts-yAxis-tick-labels .recharts-cartesian-axis-tick-value'))
        .map((label) => Array.from(label.querySelectorAll('tspan')).map((line) => line.textContent).join(' '));
      expect(labels).toEqual(expectedLabels);
      expect(Array.from(busiestDays.querySelectorAll('.recharts-label-list text')).map((label) => label.textContent))
        .toEqual(['11', '7', '7']);
      await userEvent.click(within(busiestDays).getByRole('button', { name: 'Enlarge' }));
      const dialog = await screen.findByRole('dialog', { name: 'Top 10 busiest days' });
      await waitFor(() => expect(dialog.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle'))
        .toHaveLength(expectedBars));
      await userEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
    }

    await userEvent.click(within(busiestDays).getByRole('button', { name: 'Show data' }));
    const table = within(busiestDays).getByRole('table');
    expect(within(table).getAllByRole('columnheader').map((cell) => cell.textContent)).toEqual(['Rank', 'Date', 'Visits']);
    expect(within(table).getByRole('columnheader', { name: 'Visits' })).toHaveClass('text-right');
    if (expectedBars > 0) {
      expect(within(table).getAllByRole('row').slice(1).map((row) =>
        Array.from(row.children).map((cell) => cell.textContent))).toEqual([
        ['1', 'Sun, Nov 2, 2025', '11'], ['2', 'Mon, Nov 3, 2025', '7'], ['3', 'Tue, Nov 4, 2025', '7'],
      ]);
    }
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

  it('shows admin history management after data loads and opens and closes an accessible dialog', async () => {
    auth.isAdmin = true;
    const request = pendingFigures();
    api.fetchVisualizations.mockReturnValue(request.promise);
    const user = userEvent.setup();
    renderPage();
    await screen.findByLabelText('Acquisitions unit');
    expect(screen.queryByRole('button', { name: 'Manage historical figures' })).not.toBeInTheDocument();

    await act(async () => { request.resolve(figures); await request.promise; });
    const trigger = await screen.findByRole('button', { name: 'Manage historical figures' });
    expect(trigger.closest('header')).toContainElement(screen.getByLabelText('Acquisitions unit'));
    expect(trigger.closest('header')).toContainElement(screen.getByLabelText('Years'));
    await user.click(trigger);

    let dialog = await screen.findByRole('dialog', { name: 'Historical figures' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveClass('w-full', 'max-h-[min(88vh,52rem)]');
    const panel = within(dialog).getByRole('region', { name: 'Historical figures' });
    expect(panel).toHaveTextContent('Manage stored figures for SC');
    const scrollRegion = Array.from(dialog.children).find((child) => child.classList.contains('overflow-y-auto'));
    expect(scrollRegion).toHaveClass('min-h-0', 'overflow-y-auto');
    expect(document.activeElement).toBe(within(dialog).getByRole('button', { name: 'Close' }));
    expect(document.body.style.overflow).toBe('hidden');
    await user.click(within(panel).getByRole('button', { name: 'Add historical figure' }));
    expect(within(panel).getByLabelText('Fiscal year')).toHaveValue(2027);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'Historical figures' })).not.toBeInTheDocument();
    expect(document.activeElement).toBe(trigger);
    expect(document.body.style.overflow).toBe('');

    await user.click(trigger);
    dialog = await screen.findByRole('dialog', { name: 'Historical figures' });
    await user.click(within(dialog).getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog', { name: 'Historical figures' })).not.toBeInTheDocument();
    expect(document.activeElement).toBe(trigger);
  });

  it('supports admin history add, edit, delete and field errors while omitting the panel for non-admins', async () => {
    {
      auth.isAdmin = true;
      const added = { ...historyRow, id: 22, fiscalYearEnd: 2028, note: 'Added figure' };
      api.saveVisualizationHistory.mockResolvedValue(added);
      api.fetchVisualizationHistory.mockResolvedValueOnce([historyRow]).mockResolvedValue([historyRow, added]);
      renderPage();
      await openHistoryEditor();
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
      const panel = await openHistoryEditor();
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
      const panel = await openHistoryEditor();
      const row = await within(panel).findByRole('row', { name: /2026.*purchases/i });
      await userEvent.click(within(row).getByRole('button', { name: /delete/i }));
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
      const panel = await openHistoryEditor();
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
      expect(screen.queryByRole('button', { name: 'Manage historical figures' })).not.toBeInTheDocument();
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
      const panel = await openHistoryEditor();
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
    const panel = await openHistoryEditor();
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

describe('Visualizations RPT-030 chart enlargement', () => {
  const chartExplanations: Record<string, string> = {
    'How the library purchases': "Electronic, physical and other purchases as a share of each fiscal year's spending.",
    'How the library purchases by material': 'Paid FOLIO purchases by material type; slices show positive amounts, and the table retains every value.',
    'Library budget': 'Total budget allocated to the acquisitions unit, with the API-reported year-over-year change.',
  };

  it('gives every visualization ChartCard an Enlarge button', async () => {
    await renderWithData({});

    for (const title of chartTitles) {
      const region = await chart(title);
      expect.soft(Boolean(within(region).queryByRole('button', { name: 'Enlarge' }))).toBe(true);
    }
  });

  it('opens an accessible dialog labelled by the selected chart title', async () => {
    await renderWithData({});
    const { enlarge, dialog } = await openChartIfAvailable(chartTitles[0]);
    const accessibleDialog = screen.queryByRole('dialog', { name: chartTitles[0] });
    const labelledBy = dialog?.getAttribute('aria-labelledby');

    expect.soft(Boolean(enlarge)).toBe(true);
    expect.soft(Boolean(dialog)).toBe(true);
    expect.soft(dialog?.getAttribute('aria-modal')).toBe('true');
    expect.soft(Boolean(labelledBy)).toBe(true);
    expect.soft(labelledBy ? document.getElementById(labelledBy)?.textContent?.trim() : null).toBe(chartTitles[0]);
    expect.soft(accessibleDialog).toBe(dialog);
  });

  it('keeps the selected chart, its explanation, legend and controls in the dialog', async () => {
    await renderWithData({});

    for (const title of chartTitles) {
      const { region, enlarge, dialog } = await openChartIfAvailable(title);
      const dialogTitle = dialog?.querySelector('h2')?.textContent?.trim();
      const hasChart = Boolean(dialog?.querySelector('svg.recharts-surface'));
      const hasCloseButton = Boolean(dialog && within(dialog).queryByRole('button', { name: 'Close' }));

      expect.soft(Boolean(enlarge), `${title} enlarge control`).toBe(true);
      expect.soft(Boolean(dialog), `${title} dialog`).toBe(true);
      expect.soft(dialogTitle, `${title} heading`).toBe(title);
      expect.soft(dialog?.textContent ?? '').toContain(chartExplanations[title]);
      expect.soft(hasChart, `${title} chart`).toBe(true);
      expect.soft(hasCloseButton, `${title} close control`).toBe(true);
      expect.soft(Boolean(region.querySelector('svg.recharts-surface'))).toBe(true);

      if (title === chartTitles[1]) {
        expect.soft(Boolean(dialog && within(dialog).queryByLabelText('Material fiscal year'))).toBe(true);
        expect.soft(dialog?.textContent ?? '').toContain('Database');
      } else if (title === chartTitles[0]) {
        expect.soft(dialog?.textContent ?? '').toContain('Electronic');
        expect.soft(dialog?.textContent ?? '').toContain('Stored history');
      } else {
        expect.soft(dialog?.textContent ?? '').toContain('Stored history');
      }

      const closeButton = dialog ? within(dialog).queryByRole('button', { name: 'Close' }) : null;
      if (closeButton) await userEvent.click(closeButton);
    }
  });

  it('renders the same data for every chart when enlarged', async () => {
    await renderWithData({});

    for (const title of chartTitles) {
      const { region, dialog } = await openChartIfAvailable(title);
      const selector = title === chartTitles[1]
        ? 'path.recharts-pie-sector' : 'svg .recharts-label-list text';
      const readData = (root: ParentNode | null) => Array.from(root?.querySelectorAll(selector) ?? [])
        .map((node) => title === chartTitles[1]
          ? node.getAttribute('aria-label') ?? ''
          : node.textContent?.replace(/\s+/g, ' ').trim() ?? '');
      const cardData = readData(region);
      const dialogData = readData(dialog);

      expect.soft(cardData.length, `${title} card data`).toBeGreaterThan(0);
      expect.soft(dialogData, `${title} enlarged data`).toEqual(cardData);
      expect.soft(dialogData.length, `${title} enlarged data`).toBeGreaterThan(0);

      const chartTextSizes = (root: ParentNode | null, selector: string) => Array.from(
        root?.querySelectorAll<SVGTextElement>(selector) ?? [],
        (text) => Number(text.getAttribute('font-size')),
      );
      const cardChartTextSizes = chartTextSizes(region, '.recharts-cartesian-axis-tick text, .recharts-label-list text, .recharts-pie-labels text');
      const dialogChartTextSizes = chartTextSizes(dialog, '.recharts-cartesian-axis-tick text, .recharts-label-list text, .recharts-pie-labels text');
      const legendSizes = (root: ParentNode | null) => [
        ...Array.from(root?.querySelectorAll<HTMLElement>('.chart-legend-content, .chart-history-legend') ?? [],
          (legend) => legend.classList.contains('text-[18px]') ? 18
            : legend.classList.contains('text-[13px]') ? 13 : Number.parseFloat(legend.style.fontSize)),
      ];
      const cardLegendSizes = legendSizes(region);
      const dialogLegendSizes = legendSizes(dialog);

      expect.soft(cardChartTextSizes.length, `${title} page labels and ticks`).toBeGreaterThan(0);
      expect.soft(cardChartTextSizes.every((size) => size >= 13), `${title} page labels and ticks are at least 13px`).toBe(true);
      expect.soft(dialogChartTextSizes.length, `${title} enlarged labels and ticks`).toBeGreaterThan(0);
      expect.soft(dialogChartTextSizes.every((size) => size >= 18), `${title} enlarged labels and ticks are at least 18px`).toBe(true);
      expect.soft(cardLegendSizes.length, `${title} page legend text`).toBeGreaterThan(0);
      expect.soft(cardLegendSizes.every((size) => size >= 13), `${title} page legend text is at least 13px`).toBe(true);
      expect.soft(dialogLegendSizes.length, `${title} enlarged legend text`).toBeGreaterThan(0);
      expect.soft(dialogLegendSizes.every((size) => size >= 18), `${title} enlarged legend text is at least 18px`).toBe(true);
    }
  });

  it('shares the material fiscal-year picker state between card and dialog', async () => {
    const user = userEvent.setup();
    await renderWithData({});
    const { region, dialog } = await openChartIfAvailable(chartTitles[1]);
    const cardPicker = within(region).getByLabelText('Material fiscal year') as HTMLSelectElement;
    const dialogPicker = dialog?.querySelector('select[aria-label], select') as HTMLSelectElement | null;

    expect.soft(dialogPicker?.value).toBe('2027');
    if (dialogPicker) await user.selectOptions(dialogPicker, '2026');

    expect.soft(cardPicker).toHaveValue('2026');
    expect.soft(dialogPicker?.value).toBe('2026');
  });

  it('closes on Escape and restores focus to the Enlarge button', async () => {
    const user = userEvent.setup();
    await renderWithData({});
    const { enlarge, dialog } = await openChartIfAvailable(chartTitles[0]);

    expect.soft(Boolean(dialog)).toBe(true);
    await user.keyboard('{Escape}');

    expect.soft(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect.soft(document.activeElement).toBe(enlarge);
  });

  it('closes with its Close button and restores focus to the Enlarge button', async () => {
    const user = userEvent.setup();
    await renderWithData({});
    const { enlarge, dialog } = await openChartIfAvailable(chartTitles[0]);
    const closeButton = dialog ? within(dialog).queryByRole('button', { name: 'Close' }) : null;

    expect.soft(Boolean(dialog)).toBe(true);
    expect.soft(Boolean(closeButton)).toBe(true);
    if (closeButton) await user.click(closeButton);

    expect.soft(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect.soft(document.activeElement).toBe(enlarge);
  });

  it('closes on a backdrop click and restores focus to the Enlarge button', async () => {
    await renderWithData({});
    const { enlarge, dialog } = await openChartIfAvailable(chartTitles[0]);

    expect.soft(Boolean(dialog)).toBe(true);
    if (dialog) fireEvent.click(dialog);

    expect.soft(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect.soft(document.activeElement).toBe(enlarge);
  });

  it('moves focus into the dialog when it opens', async () => {
    await renderWithData({});
    const { enlarge, dialog } = await openChartIfAvailable(chartTitles[0]);

    expect.soft(Boolean(dialog)).toBe(true);
    expect.soft(Boolean(dialog?.contains(document.activeElement))).toBe(true);
    expect.soft(document.activeElement).not.toBe(enlarge);
  });

  it('keeps Tab and Shift+Tab focus inside the open dialog', async () => {
    const user = userEvent.setup();
    await renderWithData({});
    const { dialog } = await openChartIfAvailable(chartTitles[0]);
    const focusable = Array.from(dialog?.querySelectorAll<HTMLElement>(
      'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ) ?? []);

    expect.soft(Boolean(dialog)).toBe(true);
    expect.soft(focusable.length).toBeGreaterThan(0);
    if (focusable.length > 0) {
      focusable[0].focus();
      await user.tab({ shift: true });
      expect.soft(Boolean(dialog?.contains(document.activeElement))).toBe(true);

      focusable[focusable.length - 1].focus();
      await user.tab();
      expect.soft(Boolean(dialog?.contains(document.activeElement))).toBe(true);
    }
  });

  it('shows and calls Full screen only when the Fullscreen API is available', async () => {
    const supported = setFullscreenApi(true);
    const user = userEvent.setup();
    try {
      await renderWithData({});
      const { dialog } = await openChartIfAvailable(chartTitles[0]);
      const fullscreenButton = dialog ? within(dialog).queryByRole('button', { name: 'Full screen' }) : null;

      expect.soft(Boolean(dialog)).toBe(true);
      expect.soft(Boolean(fullscreenButton)).toBe(true);
      if (fullscreenButton) await user.click(fullscreenButton);
      expect.soft(supported.requestFullscreen).toHaveBeenCalledTimes(1);
      expect.soft(supported.requestFullscreen.mock.contexts[0]).toBe(dialog);

      cleanup();
      // renderWithData waits for a fresh request, so discard the prior scenario's call count.
      api.fetchVisualizations.mockClear();
      supported.restore();
      const unsupported = setFullscreenApi(false);
      try {
        await renderWithData({});
        const unsupportedDialog = (await openChartIfAvailable(chartTitles[0])).dialog;
        const hiddenButton = unsupportedDialog
          ? within(unsupportedDialog).queryByRole('button', { name: 'Full screen' }) : null;
        expect.soft(Boolean(unsupportedDialog)).toBe(true);
        expect.soft(hiddenButton).not.toBeInTheDocument();
      } finally {
        unsupported.restore();
      }
    } finally {
      supported.restore();
    }
  });
});
