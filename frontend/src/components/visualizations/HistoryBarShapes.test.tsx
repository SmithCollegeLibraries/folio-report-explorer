import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, waitFor } from '@testing-library/react';
import type { VisualizationData } from '../../api/visualizations';
import { BudgetCharts } from './BudgetCharts';
import { PurchaseCharts } from './PurchaseCharts';

const data: VisualizationData = {
  unit: { id: 'unit-sc', code: 'SC', name: 'SC' },
  currentFiscalYearEnd: 2027,
  fiscalYearEnds: [2026, 2027],
  materialFiscalYearEnds: [2026, 2027],
  purchasesByCategory: [
    { fiscalYearEnd: 2026, electronic: 600, physical: 300, other: 100, total: 1000,
      electronicPct: 60, physicalPct: 30, otherPct: 10, source: 'history' },
    { fiscalYearEnd: 2027, electronic: 700, physical: 200, other: 100, total: 1000,
      electronicPct: 70, physicalPct: 20, otherPct: 10, source: 'folio' },
  ],
  purchasesByMaterial: { fiscalYearEnd: 2027, items: [{ materialType: 'Book', amount: 1000 }] },
  budget: [
    { fiscalYearEnd: 2026, allocated: 1200, changePct: 10, source: 'history' },
    { fiscalYearEnd: 2027, allocated: 1300, changePct: 8.3, source: 'folio' },
  ],
};

beforeEach(() => {
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
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function chartSvg(container: ParentNode) {
  return Array.from(container.querySelectorAll('svg')).find((svg) => svg.querySelector('.recharts-bar-rectangles')) ?? null;
}

function barSeries(svg: SVGSVGElement) {
  return Array.from(svg.querySelectorAll('.recharts-bar-rectangles')).map((series) =>
    Array.from(series.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle')));
}

function patternForShape(shape: Element, svg: SVGSVGElement) {
  const fill = shape.getAttribute('fill') ?? '';
  const id = fill.match(/url\(#([^)]+)\)/)?.[1] ?? null;
  const pattern = id
    ? Array.from(svg.querySelectorAll('pattern')).find((candidate) => candidate.id === id) ?? null
    : null;
  return { fill, id, pattern };
}

function expectPatternReference(shape: Element, svg: SVGSVGElement) {
  const reference = patternForShape(shape, svg);
  expect.soft(reference.fill).toMatch(/^url\(#.+\)$/);
  expect.soft(reference.pattern?.tagName.toLowerCase()).toBe('pattern');
  expect.soft(reference.pattern?.closest('svg')).toBe(svg);
  return reference;
}

describe('RPT-018 history bar appearance', () => {
  it('renders purchase history fills through same-SVG patterns and leaves FOLIO shapes unpatterned', async () => {
    const { container } = render(<PurchaseCharts data={data} materialYear={2027}
      materialFiscalYearEnds={[2026, 2027]} materialLoading={false} materialAvailable
      onMaterialYear={() => {}} />);
    const svg = chartSvg(container);
    expect(svg).not.toBeNull();
    if (!svg) return;
    await waitFor(() => expect(barSeries(svg).every((series) => series.length === 2)).toBe(true));
    const series = barSeries(svg);
    const historyShapes = series.map((shapes) => shapes[0]);
    const folioShapes = series.map((shapes) => shapes[1]);

    expect.soft(series).toHaveLength(3);
    expect.soft(historyShapes.every(Boolean)).toBe(true);
    expect.soft(folioShapes.every(Boolean)).toBe(true);
    for (const shape of historyShapes) expectPatternReference(shape, svg);
    for (const shape of folioShapes) expect.soft(patternForShape(shape, svg).fill).not.toMatch(/^url\(/);
  });

  it('renders budget history fills through same-SVG patterns and leaves FOLIO shapes unpatterned', async () => {
    const { container } = render(<BudgetCharts data={data} />);
    const svg = chartSvg(container);
    expect(svg).not.toBeNull();
    if (!svg) return;
    await waitFor(() => expect(barSeries(svg).flat().length).toBe(2));
    const shapes = barSeries(svg).flat();

    expect.soft(shapes).toHaveLength(2);
    expectPatternReference(shapes[0], svg);
    expect.soft(patternForShape(shapes[1], svg).fill).not.toMatch(/^url\(/);
  });

  it('uses a distinct category-colored pattern for each purchase history segment', async () => {
    const { container } = render(<PurchaseCharts data={data} materialYear={2027}
      materialFiscalYearEnds={[2026, 2027]} materialLoading={false} materialAvailable
      onMaterialYear={() => {}} />);
    const svg = chartSvg(container);
    expect(svg).not.toBeNull();
    if (!svg) return;
    await waitFor(() => expect(barSeries(svg).every((series) => series.length === 2)).toBe(true));
    const history = barSeries(svg).map((series) => series[0]);
    const expectedColors = [
      ['var(--chart-1)', '#0072b2'],
      ['var(--chart-2)', '#e69f00'],
      ['var(--chart-3)', '#009e73'],
    ];
    const references = history.map((shape) => expectPatternReference(shape, svg));

    expect.soft(references.map(({ id }) => id).filter(Boolean)).toHaveLength(3);
    expect.soft(new Set(references.map(({ id }) => id)).size).toBe(3);
    references.forEach(({ pattern }, index) => {
      const patternColors = Array.from(pattern?.querySelectorAll('[fill], [stroke]') ?? [])
        .flatMap((element) => [element.getAttribute('fill'), element.getAttribute('stroke')])
        .filter((color): color is string => Boolean(color));
      expect.soft(expectedColors[index].some((color) => patternColors.includes(color))).toBe(true);
    });
  });
});
