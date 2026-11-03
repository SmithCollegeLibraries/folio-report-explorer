import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, waitFor } from '@testing-library/react';
import type { VisualizationData } from '../../api/visualizations';
import { BudgetCharts } from './BudgetCharts';
import { MaterialPieChart } from './MaterialPieChart';

let chartSize = { width: 800, height: 320 };
const originalTextLength = Object.getOwnPropertyDescriptor(SVGElement.prototype, 'getComputedTextLength');

function setChartSize(width: number) {
  chartSize = { width, height: 320 };
}

function mockSvgTextMeasurement() {
  Object.defineProperty(SVGElement.prototype, 'getComputedTextLength', {
    configurable: true,
    value(this: SVGElement) { return Array.from(this.textContent ?? '').length * 6; },
  });
}

beforeEach(() => {
  setChartSize(800);
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({
    x: 0, y: 0, top: 0, left: 0, right: chartSize.width, bottom: chartSize.height,
    width: chartSize.width, height: chartSize.height, toJSON: () => ({}),
  }));
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
  if (originalTextLength) Object.defineProperty(SVGElement.prototype, 'getComputedTextLength', originalTextLength);
  else Reflect.deleteProperty(SVGElement.prototype, 'getComputedTextLength');
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
});
