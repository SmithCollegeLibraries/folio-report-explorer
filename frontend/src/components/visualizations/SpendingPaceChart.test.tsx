import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SpendingPaceData } from '../../api/visualizations';
import { SpendingPaceChart } from './SpendingPaceChart';

const months = Array.from({ length: 12 }, (_, index) => ({
  fiscalMonth: index + 1,
  label: ['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'][index]
    + (index === 4 ? ' (to date)' : ''),
  currentCumulative: index < 4 ? 4 : index === 4 ? -2 : null,
  previousCumulative: index < 12 ? index === 0 ? 0 : 8 : null,
}));

const pace: SpendingPaceData = {
  available: true,
  reason: null,
  unit: { id: 'unit-sc', code: 'SC', name: 'SC' },
  currentFiscalYearEnd: 2027,
  previousFiscalYearEnd: 2026,
  asOf: '2026-11-13',
  months,
  openEncumbrances: 120.5,
  allocated: { amount: 0, source: 'history', fiscalYearEnd: 2027 },
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
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('SpendingPaceChart', () => {
  it('labels both years, marks the partial month, and exposes a captioned table with future months', () => {
    render(<SpendingPaceChart data={pace} isPending={false} onRetry={vi.fn()} />);
    const card = screen.getByRole('region', { name: 'Spending pace this year' });
    expect(within(card).getByText('Paid so far $-2.00 · Open encumbrances $120.50 · Allocated $0.00 (history)')).toBeInTheDocument();
    expect(within(card).getByText('Counts payments by the date they were paid, so totals can differ from the purchases charts, which count by invoice year.')).toBeInTheDocument();
    expect(within(card).getByText('FY2027 (this FY)')).toBeInTheDocument();
    expect(within(card).getByText('FY2026 (last FY)')).toBeInTheDocument();
    const lineLabels = within(card).getByLabelText('Spending line labels');
    expect(lineLabels.querySelectorAll('circle')).toHaveLength(2);
    expect(lineLabels.querySelector('line[stroke-dasharray="6 4"]')).toBeInTheDocument();

    fireEvent.click(within(card).getByRole('button', { name: 'Show data' }));
    const table = within(card).getByRole('table');
    expect(within(table).getByRole('columnheader', { name: 'FY2027' })).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'FY2026' })).toBeInTheDocument();
    expect(within(table).getByRole('rowheader', { name: 'Nov (to date)' })).toBeInTheDocument();
    expect(within(table).getAllByText('not yet').length).toBeGreaterThan(0);
    expect(within(table).getByText('-2.00')).toBeInTheDocument();
    const enlarge = within(card).getByRole('button', { name: 'Enlarge' });
    fireEvent.click(enlarge);
    const dialog = screen.getByRole('dialog', { name: 'Spending pace this year' });
    expect(within(dialog).getByText('Counts payments by the date they were paid, so totals can differ from the purchases charts, which count by invoice year.')).toBeInTheDocument();
    expect(within(dialog).getByText('Paid so far $-2.00 · Open encumbrances $120.50 · Allocated $0.00 (history)')).toBeInTheDocument();
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog', { name: 'Spending pace this year' })).not.toBeInTheDocument();
    expect(enlarge).toHaveFocus();
  });

  it('announces loading, explains unavailable reasons, and retries failures', () => {
    const retry = vi.fn();
    const { rerender } = render(<SpendingPaceChart isPending onRetry={retry} />);
    expect(screen.getByRole('status')).toHaveTextContent('Loading spending pace…');

    rerender(<SpendingPaceChart data={{ ...pace, available: false, reason: 'no_current_fy', allocated: { amount: 45, source: 'folio', fiscalYearEnd: 2027 }, months: [], openEncumbrances: null }} isPending={false} onRetry={retry} />);
    expect(screen.getByText('No fiscal year in FOLIO covers today')).toBeInTheDocument();
    expect(screen.getByText('Allocated $45.00 (FOLIO)')).toBeInTheDocument();

    rerender(<SpendingPaceChart error={new Error('Synthetic failure')} isPending={false} onRetry={retry} />);
    fireEvent.click(screen.getByRole('button', { name: 'Retry spending pace' }));
    expect(retry).toHaveBeenCalledOnce();
  });

  it('formats large y-axis ticks as compact currency', () => {
    const largeValues: SpendingPaceData = {
      ...pace,
      months: pace.months.map((month) => ({ ...month, currentCumulative: 0, previousCumulative: 4000000 })),
    };
    const { container } = render(<SpendingPaceChart data={largeValues} isPending={false} onRetry={vi.fn()} />);
    const chart = container.querySelector<HTMLDivElement>('div[role="img"]');
    expect(chart).not.toBeNull();
    expect(within(chart as HTMLDivElement).getByText('$4M')).toBeInTheDocument();
  });
});
