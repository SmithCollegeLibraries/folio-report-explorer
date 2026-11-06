import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { createRef } from 'react';
import type { VisualizationData } from '../../api/visualizations';
import { BudgetCharts } from './BudgetCharts';
import { ChartEnlargementDialog } from './ChartEnlargementDialog';
import { MaterialPieChart } from './MaterialPieChart';

let chartSize = { width: 800, height: 320 };
let pieSvgSize: { width: number; height: number } | null = null;
let simulateResponsiveNarrowPieLayout = false;
const originalTextLength = Object.getOwnPropertyDescriptor(SVGElement.prototype, 'getComputedTextLength');
const originalPieSvgBounds = Object.getOwnPropertyDescriptor(SVGSVGElement.prototype, 'getBoundingClientRect');
const elementGetBoundingClientRect = Element.prototype.getBoundingClientRect;

function setChartSize(width: number) {
  chartSize = { width, height: 320 };
}

function setPieSvgSize(width: number, height: number) {
  pieSvgSize = { width, height };
}

function setResponsiveNarrowPieLayout(enabled: boolean) {
  simulateResponsiveNarrowPieLayout = enabled;
}

function measuredRect(width: number, height: number) {
  return { x: 0, y: 0, top: 0, left: 0, right: width, bottom: height, width, height, toJSON: () => ({}) } as DOMRect;
}

function mockSvgTextMeasurement() {
  Object.defineProperty(SVGElement.prototype, 'getComputedTextLength', {
    configurable: true,
    value(this: SVGElement) {
      const text = this.closest('text') ?? this;
      const fontSize = Number(text.getAttribute('font-size') ?? 10);
      return Array.from(this.textContent ?? '').length * fontSize * 0.6;
    },
  });
}

beforeEach(() => {
  setChartSize(800);
  pieSvgSize = null;
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
    if (simulateResponsiveNarrowPieLayout && this.getAttribute('aria-label') === 'Paid purchases by material type') {
      const height = Number.parseFloat(this.style.height) || chartSize.height;
      return measuredRect(chartSize.width, height);
    }
    return measuredRect(chartSize.width, chartSize.height);
  });
  Object.defineProperty(SVGSVGElement.prototype, 'getBoundingClientRect', {
    configurable: true,
    value(this: SVGSVGElement) {
      if (this.getAttribute('aria-label') === 'Paid purchases by material type') {
        const inlineHeight = Number.parseFloat(this.style.height);
        const size = pieSvgSize ?? (simulateResponsiveNarrowPieLayout && chartSize.width < 480
          ? { width: chartSize.width, height: inlineHeight || 150 }
          : chartSize);
        return measuredRect(size.width, size.height);
      }
      return elementGetBoundingClientRect.call(this);
    },
  });
  vi.stubGlobal('ResizeObserver', class {
    constructor(private callback: ResizeObserverCallback) {}
    observe(target: Element) {
      this.callback([{ target, contentRect: target.getBoundingClientRect() } as ResizeObserverEntry], this as unknown as ResizeObserver);
    }
    unobserve() {}
    disconnect() {}
  });
  mockSvgTextMeasurement();
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  if (originalPieSvgBounds) Object.defineProperty(SVGSVGElement.prototype, 'getBoundingClientRect', originalPieSvgBounds);
  else Reflect.deleteProperty(SVGSVGElement.prototype, 'getBoundingClientRect');
  if (originalTextLength) Object.defineProperty(SVGElement.prototype, 'getComputedTextLength', originalTextLength);
  else Reflect.deleteProperty(SVGElement.prototype, 'getComputedTextLength');
  pieSvgSize = null;
  simulateResponsiveNarrowPieLayout = false;
});

function viewBox(svg: SVGSVGElement) {
  const [x, y, width, height] = (svg.getAttribute('viewBox') ?? '').split(/\s+/).map(Number);
  return { x, y, width, height };
}

function circleGeometry(path: SVGPathElement) {
  const d = path.getAttribute('d') ?? '';
  const center = d.match(/^M\s+([\d.]+)\s+([\d.]+)\s+L/);
  const arc = d.match(/\bA\s+([\d.]+)\s+([\d.]+)/);
  if (!center || !arc) throw new Error(`Expected a pie sector path with an arc: ${d}`);
  return { centerX: Number(center[1]), centerY: Number(center[2]), radiusX: Number(arc[1]), radiusY: Number(arc[2]) };
}

function budgetDataForAmounts(amounts: number[], changePct: number | null = null): VisualizationData {
  const fiscalYearEnds = amounts.map((_, index) => 2023 + index);
  const currentFiscalYearEnd = fiscalYearEnds[fiscalYearEnds.length - 1] ?? 2024;
  return {
    unit: { id: 'unit-sc', code: 'SC', name: 'SC' },
    currentFiscalYearEnd,
    fiscalYearEnds,
    materialFiscalYearEnds: [],
    purchasesByCategory: [],
    purchasesByMaterial: { fiscalYearEnd: currentFiscalYearEnd, items: [] },
    budget: amounts.map((allocated, index) => ({
      fiscalYearEnd: 2023 + index, allocated, changePct, source: 'folio' as const,
    })),
  };
}

async function firstBudgetBarTop(amounts: number[]) {
  const data = budgetDataForAmounts(amounts);
  const { container, unmount } = render(<BudgetCharts data={data} />);
  const svg = Array.from(container.querySelectorAll('svg'))
    .find((candidate) => candidate.querySelector('.recharts-bar-rectangles'));
  expect(svg).toBeDefined();
  if (!svg) { unmount(); return Number.NaN; }

  await waitFor(() => expect(svg.querySelectorAll('.recharts-label-list text').length).toBe(amounts.length));
  const labels = Array.from(svg.querySelectorAll('.recharts-label-list text'));
  expect(labels.every((label) => !label.getAttribute('transform')?.includes('rotate(-90'))).toBe(true);
  const firstBar = svg.querySelector('.recharts-bar-rectangle .recharts-rectangle');
  expect(firstBar).not.toBeNull();
  const top = Number(firstBar?.getAttribute('y'));
  unmount();
  return top;
}

function expectSideLabelsInsideSvg(svg: SVGSVGElement) {
  const bounds = viewBox(svg);
  const labels = Array.from(svg.querySelectorAll('.recharts-pie-labels text'));
  expect(labels.length).toBeGreaterThan(0);

  for (const label of labels) {
    const x = Number(label.getAttribute('x'));
    const y = Number(label.getAttribute('y'));
    const width = (label as SVGTextElement).getComputedTextLength();
    const height = Number(label.getAttribute('font-size') ?? 10);
    const left = label.getAttribute('text-anchor') === 'end' ? x - width : x;
    const right = label.getAttribute('text-anchor') === 'end' ? x : x + width;
    expect.soft(left).toBeGreaterThanOrEqual(bounds.x);
    expect.soft(right).toBeLessThanOrEqual(bounds.x + bounds.width);
    expect.soft(y - height).toBeGreaterThanOrEqual(bounds.y);
    expect.soft(y + 2).toBeLessThanOrEqual(bounds.y + bounds.height);
  }

  return labels;
}

const singleMaterial = [{ materialType: 'Book', amount: 1000 }];
const labelledMaterials = [
  { materialType: 'Database', amount: 553 },
  { materialType: 'Admin', amount: 201 },
  { materialType: 'Book', amount: 80 },
  { materialType: 'E-Journal', amount: 56 },
  { materialType: 'E-Book', amount: 52 },
  { materialType: 'Journal', amount: 16 },
  { materialType: 'Score', amount: 42 },
];

describe('RPT-024 visualization bounds', () => {
  it('gives a narrow phone pie a square above its legend and keeps the wide box', async () => {
    setChartSize(330);
    setResponsiveNarrowPieLayout(true);
    vi.stubGlobal('innerWidth', 375);

    const narrowRender = render(<MaterialPieChart items={labelledMaterials} />);
    const narrowSvg = narrowRender.container.querySelector('svg');
    const narrowChart = narrowRender.container.querySelector('[data-chart]');
    expect(narrowSvg).not.toBeNull();
    expect(narrowChart).not.toBeNull();
    if (!narrowSvg || !narrowChart) return;

    await waitFor(() => expect(narrowSvg.querySelector('.recharts-pie-sector')).not.toBeNull());
    const narrowPath = narrowSvg.querySelector('.recharts-pie-sector');
    const narrowLegendItem = narrowRender.container.querySelector('.recharts-legend-item');
    const narrowLegend = narrowLegendItem?.parentElement;
    expect(narrowPath).not.toBeNull();
    if (!narrowPath || !narrowLegend) return;
    const narrowSvgBounds = narrowSvg.getBoundingClientRect();
    const narrowChartBounds = narrowChart.getBoundingClientRect();
    const { radiusX } = circleGeometry(narrowPath as SVGPathElement);

    expect.soft(narrowSvgBounds.width).toBeCloseTo(330, 0);
    expect.soft(narrowSvgBounds.height).toBeCloseTo(narrowSvgBounds.width, 0);
    expect.soft(narrowSvgBounds.height).toBeGreaterThanOrEqual(260);
    expect.soft(radiusX).toBeGreaterThanOrEqual(140);
    expect.soft(narrowChartBounds.height).toBeGreaterThanOrEqual(narrowSvgBounds.height);
    expect.soft(narrowChart.contains(narrowLegend)).toBe(false);
    expect.soft(narrowRender.container.querySelectorAll('.recharts-legend-item')).toHaveLength(7);
    narrowRender.unmount();

    setChartSize(640);
    vi.stubGlobal('innerWidth', 1280);
    const wideRender = render(<MaterialPieChart items={labelledMaterials} />);
    const wideSvg = wideRender.container.querySelector('svg');
    expect(wideSvg).not.toBeNull();
    if (!wideSvg) return;
    await waitFor(() => expect(wideSvg.querySelector('.recharts-pie-sector')).not.toBeNull());
    const widePath = wideSvg.querySelector('.recharts-pie-sector');
    expect(widePath).not.toBeNull();
    if (!widePath) return;
    const { radiusX: wideRadius } = circleGeometry(widePath as SVGPathElement);
    expect.soft(viewBox(wideSvg)).toEqual({ x: 0, y: 0, width: 640, height: 320 });
    expect.soft(wideRadius).toBe(144);
  });

  it('sizes the material pie responsively inside its SVG', async () => {
    const radii: number[] = [];

    for (const width of [420, 640, 800]) {
      setChartSize(width);
      const { container, unmount } = render(<MaterialPieChart items={singleMaterial} />);
      const svg = container.querySelector('svg');
      expect(svg).not.toBeNull();
      if (!svg) { unmount(); continue; }

      await waitFor(() => expect(svg.querySelector('.recharts-pie-sector')).not.toBeNull());
      const path = svg.querySelector('.recharts-pie-sector');
      expect(path).not.toBeNull();
      if (!path) { unmount(); continue; }
      const { centerX, centerY, radiusX, radiusY } = circleGeometry(path as SVGPathElement);
      const bounds = viewBox(svg);
      radii.push(radiusX);

      expect.soft(radiusX).toBe(radiusY);
      expect.soft(centerX - radiusX).toBeGreaterThanOrEqual(bounds.x);
      expect.soft(centerY - radiusY).toBeGreaterThanOrEqual(bounds.y);
      expect.soft(centerX + radiusX).toBeLessThanOrEqual(bounds.x + bounds.width);
      expect.soft(centerY + radiusY).toBeLessThanOrEqual(bounds.y + bounds.height);
      unmount();
    }

    expect.soft(radii).toHaveLength(3);
    expect.soft(radii[1]).toBeGreaterThanOrEqual(radii[0]);
    expect.soft(radii[2]).toBeGreaterThanOrEqual(radii[1]);
    expect.soft(radii[1]).toBeGreaterThanOrEqual(110);
  });

  it('keeps a large pie with labels on both sides at the measured 640px and 800px widths', async () => {
    for (const width of [640, 800]) {
      setChartSize(width);
      const { container, unmount } = render(<MaterialPieChart items={labelledMaterials} />);
      const svg = container.querySelector('svg');
      expect(svg).not.toBeNull();
      if (!svg) { unmount(); continue; }

      await waitFor(() => expect(svg.querySelectorAll('.recharts-pie-labels text').length).toBeGreaterThan(0));
      const path = svg.querySelector('.recharts-pie-sector');
      expect(path).not.toBeNull();
      if (!path) { unmount(); continue; }
      const { radiusX } = circleGeometry(path as SVGPathElement);
      const labels = expectSideLabelsInsideSvg(svg);

      expect.soft(viewBox(svg).width).toBe(width);
      expect.soft(radiusX).toBeGreaterThanOrEqual(110);
      expect.soft(labels.some((label) => label.getAttribute('text-anchor') === 'start')).toBe(true);
      expect.soft(labels.some((label) => label.getAttribute('text-anchor') === 'end')).toBe(true);
      unmount();
    }
  });

  it('fits long material side-label text within the SVG using deterministic text widths', async () => {
    setChartSize(640);
    const items = [
      { materialType: 'Electronic Resource Subscription Package - Multi-year', amount: 400 },
      { materialType: 'Database', amount: 300 },
      { materialType: 'Book', amount: 200 },
      { materialType: 'Other', amount: 100 },
    ];
    const { container } = render(<MaterialPieChart items={items} />);
    const svg = container.querySelector('svg');
    expect(svg).not.toBeNull();
    if (!svg) return;

    await waitFor(() => expect(svg.querySelectorAll('.recharts-pie-labels text').length).toBeGreaterThan(0));
    const labels = expectSideLabelsInsideSvg(svg);
    expect(labels.some((label) => label.textContent?.includes('Electronic'))).toBe(true);
  });

  it('keeps the previous plot top when wide bars fit long horizontal amount labels', async () => {
    setChartSize(800);
    const shortAmountBarTop = await firstBudgetBarTop([12_000, 8_000]);
    const longAmountBarTop = await firstBudgetBarTop([12_000_000_000, 8_000_000_000]);

    expect(longAmountBarTop).toBeCloseTo(shortAmountBarTop, 5);
  });

  it('keeps ten-year horizontal budget labels near the plot top without clipping', async () => {
    setChartSize(800);
    const data = budgetDataForAmounts(Array.from({ length: 10 }, () => 12_000), 2.3);
    const { container } = render(<BudgetCharts data={data} />);
    const svg = Array.from(container.querySelectorAll('svg'))
      .find((candidate) => candidate.querySelector('.recharts-bar-rectangles'));
    expect(svg).toBeDefined();
    if (!svg) return;

    await waitFor(() => expect(svg.querySelectorAll('.recharts-label-list text').length).toBe(10));
    const plotTop = svg.querySelector('.recharts-cartesian-grid-horizontal line');
    const labels = Array.from(svg.querySelectorAll('.recharts-label-list text'));
    expect(plotTop).not.toBeNull();
    expect(labels.every((label) => !label.getAttribute('transform')?.includes('rotate(-90'))).toBe(true);
    expect(labels.every((label) => label.querySelectorAll('tspan').length === 2)).toBe(true);
    expect.soft(Number(plotTop?.getAttribute('y1'))).toBeLessThanOrEqual(56);

    for (const label of labels) {
      const baseline = Number(label.getAttribute('y'));
      const fontSize = Number(label.getAttribute('font-size') ?? 11);
      const lines = Array.from(label.querySelectorAll('tspan'));
      for (const [index, line] of lines.entries()) {
        const offset = index === 0 ? 0 : Number(line.getAttribute('dy') ?? 0);
        expect.soft(baseline + offset - fontSize).toBeGreaterThanOrEqual(0);
      }
    }
  });

  it('sizes the pie from the measured SVG box above the legend', async () => {
    setChartSize(640);
    setPieSvgSize(640, 240);
    const { container } = render(<MaterialPieChart items={singleMaterial} />);
    const svg = container.querySelector('svg');
    expect(svg).not.toBeNull();
    if (!svg) return;

    await waitFor(() => expect(svg.querySelector('.recharts-pie-sector')).not.toBeNull());
    const path = svg.querySelector('.recharts-pie-sector');
    expect(path).not.toBeNull();
    if (!path) return;
    const { centerX, centerY, radiusX } = circleGeometry(path as SVGPathElement);
    const bounds = viewBox(svg);

    expect.soft(bounds).toEqual({ x: 0, y: 0, width: 640, height: 240 });
    expect.soft(radiusX).toBeLessThanOrEqual(240 / 2 - 16);
    expect.soft(centerX - radiusX).toBeGreaterThanOrEqual(0);
    expect.soft(centerX + radiusX).toBeLessThanOrEqual(640);
    expect.soft(centerY - radiusX).toBeGreaterThanOrEqual(0);
    expect.soft(centerY + radiusX).toBeLessThanOrEqual(240);
  });

  it('grows the narrow pie with SVG width up to the SVG height limit', async () => {
    const radii: number[] = [];
    setChartSize(420);

    for (const width of [240, 340]) {
      setPieSvgSize(width, 320);
      const { container, unmount } = render(<MaterialPieChart items={singleMaterial} />);
      const svg = container.querySelector('svg');
      expect(svg).not.toBeNull();
      if (!svg) { unmount(); continue; }

      await waitFor(() => expect(svg.querySelector('.recharts-pie-sector')).not.toBeNull());
      const path = svg.querySelector('.recharts-pie-sector');
      expect(path).not.toBeNull();
      if (!path) { unmount(); continue; }
      const { centerX, radiusX } = circleGeometry(path as SVGPathElement);
      const bounds = viewBox(svg);
      radii.push(radiusX);

      expect.soft(bounds).toEqual({ x: 0, y: 0, width, height: 320 });
      expect.soft(centerX - radiusX).toBeGreaterThanOrEqual(0);
      expect.soft(centerX + radiusX).toBeLessThanOrEqual(width);
      expect.soft(radiusX).toBeLessThanOrEqual(320 / 2 - 16);
      unmount();
    }

    expect.soft(radii[1]).toBeGreaterThan(radii[0]);
  });

  it('keeps rotated budget amount labels below the top SVG edge on narrow bars', async () => {
    setChartSize(420);
    const data: VisualizationData = {
      unit: { id: 'unit-sc', code: 'SC', name: 'SC' },
      currentFiscalYearEnd: 2024,
      fiscalYearEnds: [2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024],
      materialFiscalYearEnds: [],
      purchasesByCategory: [],
      purchasesByMaterial: { fiscalYearEnd: 2024, items: [] },
      budget: Array.from({ length: 9 }, (_, index) => ({
        fiscalYearEnd: 2016 + index,
        allocated: 987_654_321_000 + index * 1_000_000,
        changePct: 1,
        source: 'folio' as const,
      })),
    };
    const { container } = render(<BudgetCharts data={data} />);
    const svg = Array.from(container.querySelectorAll('svg'))
      .find((candidate) => candidate.querySelector('.recharts-bar-rectangles'));
    expect(svg).toBeDefined();
    if (!svg) return;

    await waitFor(() => expect(svg.querySelectorAll('.recharts-label-list text').length).toBe(9));
    const rotatedLabels = Array.from(svg.querySelectorAll('.recharts-label-list text'))
      .filter((label) => label.getAttribute('transform')?.includes('rotate(-90'));
    expect(rotatedLabels.length).toBeGreaterThan(0);

    for (const label of rotatedLabels) {
      const y = Number(label.getAttribute('y'));
      const amount = label.querySelector('tspan');
      expect(amount).not.toBeNull();
      if (!amount) continue;
      expect.soft(y - (amount as SVGTSpanElement).getComputedTextLength()).toBeGreaterThanOrEqual(0);
    }
  });

  it('uses enlarged text estimates to keep pie and budget labels inside their chart bounds', async () => {
    setChartSize(640);
    const data = budgetDataForAmounts(Array.from({ length: 9 }, () => 987_654_321_000), 1);
    render(<ChartEnlargementDialog title="Charts" explanation="Enlarged charts"
      triggerRef={createRef<HTMLButtonElement>()} onClose={() => undefined}>
      <MaterialPieChart items={labelledMaterials} />
      <BudgetCharts data={data} />
    </ChartEnlargementDialog>);
    const dialog = screen.getByRole('dialog', { name: 'Charts' });
    const pieSvg = dialog.querySelector('.recharts-pie-labels')?.closest('svg');
    const budgetSvg = Array.from(dialog.querySelectorAll('svg'))
      .find((candidate) => candidate.querySelector('.recharts-bar-rectangles'));
    expect(pieSvg).not.toBeNull();
    expect(budgetSvg).toBeDefined();
    if (!pieSvg || !budgetSvg) return;

    await waitFor(() => {
      expect(pieSvg.querySelectorAll('.recharts-pie-labels text').length).toBeGreaterThan(0);
      expect(budgetSvg.querySelectorAll('.recharts-label-list text').length).toBe(9);
    });
    const pieLabels = expectSideLabelsInsideSvg(pieSvg);
    const budgetLabels = Array.from(budgetSvg.querySelectorAll('.recharts-label-list text'));
    const plotTop = budgetSvg.querySelector('.recharts-cartesian-grid-horizontal line');

    expect(pieLabels.every((label) => label.getAttribute('font-size') === '18')).toBe(true);
    expect(budgetLabels.every((label) => label.getAttribute('font-size') === '18')).toBe(true);
    expect(Number(plotTop?.getAttribute('y1'))).toBeGreaterThan(90);
    expect(budgetLabels.every((label) => {
      const y = Number(label.getAttribute('y'));
      const amount = label.querySelector('tspan');
      return amount !== null && y - amount.getComputedTextLength() >= 0;
    })).toBe(true);
  });
});
