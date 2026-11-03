import type { ReactNode } from 'react';
import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { VisualizationData } from '../../api/visualizations';

vi.mock('recharts', async () => {
  const React = await import('react');
  const Cell = (props: Record<string, unknown>) => <rect className="recharts-bar-shape" {...props} />;
  return {
    ResponsiveContainer: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    BarChart: ({ children }: { children: ReactNode }) => <svg className="recharts-surface">{children}</svg>,
    Bar: ({ children, fill, dataKey }: { children?: ReactNode; fill?: string; dataKey?: string }) => {
      const cells = React.Children.toArray(children).filter((child): child is React.ReactElement<Record<string, unknown>> =>
        React.isValidElement(child) && child.type === Cell);
      return <g className="recharts-bar-rectangles" data-key={dataKey}>
        {cells.map((cell, index) => React.cloneElement(cell, {
          key: index, fill: cell.props.fill ?? fill,
        }))}
      </g>;
    },
    Cell,
    CartesianGrid: () => null,
    XAxis: () => null,
    YAxis: () => null,
    Tooltip: () => null,
    Legend: () => null,
    LabelList: () => null,
  };
});

import { PurchaseCharts } from './PurchaseCharts';
import { BudgetCharts } from './BudgetCharts';

const data: VisualizationData = {
  unit: { id: 'unit-sc', code: 'SC', name: 'SC' },
  currentFiscalYearEnd: 2027,
  fiscalYearEnds: [2025, 2026, 2027],
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

function barShapes(container: ParentNode) {
  const shapes = Array.from(container.querySelectorAll('.recharts-bar-rectangles .recharts-bar-shape'));
  return {
    shapes,
    stored: shapes.filter((shape) => shape.getAttribute('stroke-dasharray') === '4 3'
      || shape.getAttribute('fill')?.startsWith('url(')),
    folio: shapes.filter((shape) => !shape.hasAttribute('stroke-dasharray')
      && !shape.getAttribute('fill')?.startsWith('url(')),
  };
}

describe('RPT-018 history bar appearance', () => {
  it('puts the stored-history pattern on purchase bar shapes, not the legend or grid', () => {
    const { container } = render(<PurchaseCharts data={data} materialYear={2027}
      materialFiscalYearEnds={[2026, 2027]} materialLoading={false} materialAvailable
      onMaterialYear={() => {}} />);
    const categoryChart = container.querySelector('section[aria-labelledby]');
    const bars = categoryChart ? barShapes(categoryChart) : { shapes: [], stored: [], folio: [] };

    expect.soft(bars.shapes.length).toBeGreaterThan(0);
    expect.soft(bars.stored.length).toBeGreaterThan(0);
    expect.soft(bars.folio.length).toBeGreaterThan(0);
  });

  it('puts the stored-history pattern on budget bar shapes, not the legend or grid', () => {
    const { container } = render(<BudgetCharts data={data} />);
    const budgetChart = Array.from(container.querySelectorAll('section[aria-labelledby]'))
      .find((section) => section.querySelector('h2')?.textContent === 'Library budget');
    const bars = budgetChart ? barShapes(budgetChart) : { shapes: [], stored: [], folio: [] };

    expect.soft(bars.shapes.length).toBeGreaterThan(0);
    expect.soft(bars.stored.length).toBeGreaterThan(0);
    expect.soft(bars.folio.length).toBeGreaterThan(0);
  });
});
