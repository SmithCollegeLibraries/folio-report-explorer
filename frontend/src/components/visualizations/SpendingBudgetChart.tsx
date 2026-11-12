import { useId } from 'react';
import { Bar, CartesianGrid, Cell, ComposedChart, Line, XAxis, YAxis } from 'recharts';
import type { VisualizationData } from '../../api/visualizations';
import { ChartContainer, type ChartConfig } from '../ui/chart';
import { ChartTooltip } from '../ui/chart-tooltip';
import { ChartCard, amount } from './ChartCard';
import { useChartLabelSize } from './ChartLabelSize';
import { mergeSpendingBudgetRows, type SpendingBudgetRow } from './spendingBudgetRows';

const categories = [
  { key: 'electronic', label: 'Electronic', color: 'var(--chart-1)' },
  { key: 'physical', label: 'Physical', color: 'var(--chart-2)' },
  { key: 'other', label: 'Other', color: 'var(--chart-3)' },
] as const;
const config: ChartConfig = {
  electronic: { label: 'Electronic', color: 'var(--chart-1)' },
  physical: { label: 'Physical', color: 'var(--chart-2)' },
  other: { label: 'Other', color: 'var(--chart-3)' },
  allocated: { label: 'Allocated', color: '#334155' },
};
const compactCurrency = new Intl.NumberFormat('en-US', {
  style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1,
});

function sourceName(source: SpendingBudgetRow['purchasesSource']) {
  switch (source) {
    case 'history': return 'Stored history';
    case 'folio': return 'FOLIO';
    default: return 'n/a';
  }
}

function displayAmount(value: number | null) {
  return value === null ? 'n/a' : amount(value);
}

function ChartKey() {
  return <div className="mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600">
    {categories.map((category) => <span key={category.key} className="flex items-center gap-2">
      <span aria-hidden="true" className="inline-block h-3 w-3 rounded-sm" style={{ backgroundColor: category.color }} />
      {category.label}
    </span>)}
    <span className="flex items-center gap-2"><svg aria-hidden="true" width="30" height="14">
      <line x1="1" y1="7" x2="29" y2="7" stroke="#334155" strokeWidth="2" />
      <circle cx="15" cy="7" r="4" fill="#334155" stroke="#334155" strokeWidth="2" />
    </svg>Allocated</span>
    <span className="flex items-center gap-2"><svg aria-hidden="true" width="14" height="14">
      <circle cx="7" cy="7" r="5" fill="white" stroke="#334155" strokeWidth="2" />
    </svg>Hollow dot: stored history allocation</span>
  </div>;
}

function AllocationDot({ cx, cy, payload }: { cx?: number; cy?: number; payload?: SpendingBudgetRow }) {
  if (cx === undefined || cy === undefined) return null;
  const historical = payload?.allocationSource === 'history';
  return <circle cx={cx} cy={cy} r={5} fill={historical ? 'white' : '#334155'} stroke="#334155" strokeWidth={2} />;
}

function SpendingBudgetPlot({ rows }: { rows: SpendingBudgetRow[] }) {
  const labelSize = useChartLabelSize();
  const patternId = useId().replace(/:/g, '');
  return <div className="h-80 min-w-0 w-full" aria-label="Paid purchases and allocated budget by fiscal year">
    <ChartContainer config={config} className="h-full min-w-0 w-full">
      <ComposedChart data={rows} margin={{ top: 8, right: 12, bottom: 8, left: 8 }} barCategoryGap={2} barGap={2}>
        <defs>{categories.map((category) => <pattern key={category.key} id={`${patternId}-${category.key}`}
          width="6" height="6" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">
          <rect width="6" height="6" fill="#f8fafc" />
          <path d="M 0 0 V 6" stroke={category.color} strokeWidth="2" />
        </pattern>)}</defs>
        <CartesianGrid stroke="#e5e7eb" strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="fiscalYearEnd" tickLine={false} axisLine={false} tick={{ fontSize: labelSize }} />
        <YAxis width={76} domain={['dataMin', 'dataMax']} tickFormatter={(value) => compactCurrency.format(Number(value))}
          tickLine={false} axisLine={false} tick={{ fontSize: labelSize }} />
        <ChartTooltip content={({ active, payload }) => {
          if (!active || !payload?.length) return null;
          const row = payload[0].payload as SpendingBudgetRow;
          return <div className="grid gap-1.5 rounded-md border border-gray-200 bg-white p-3 text-sm shadow-md">
            <strong>FY {row.fiscalYearEnd}</strong>
            <p>Electronic: {displayAmount(row.electronic)}</p><p>Physical: {displayAmount(row.physical)}</p>
            <p>Other: {displayAmount(row.other)}</p><p>Total paid: {displayAmount(row.total)}</p>
            <p>Purchases source: {sourceName(row.purchasesSource)}</p>
            <p>Allocated: {displayAmount(row.allocated)}</p>
            <p>Allocation source: {sourceName(row.allocationSource)}</p>
          </div>;
        }} />
        {categories.map((category) => <Bar key={category.key} dataKey={category.key} name={category.label}
          stackId="paid" fill={category.color} isAnimationActive={false}>
          {rows.map((row) => <Cell key={row.fiscalYearEnd}
            fill={row.purchasesSource === 'history' ? `url(#${patternId}-${category.key})` : category.color} />)}
        </Bar>)}
        <Line dataKey="allocated" name="Allocated" type="linear" stroke="#334155" strokeWidth={2}
          connectNulls={false} isAnimationActive={false} dot={AllocationDot} activeDot={AllocationDot} />
      </ComposedChart>
    </ChartContainer>
  </div>;
}

export function SpendingBudgetChart({ data }: { data: VisualizationData }) {
  const title = 'Spending vs budget';
  const rows = mergeSpendingBudgetRows(data.purchasesByCategory, data.budget);
  return <ChartCard title={title} history
    explanation="Paid Electronic, Physical, and Other purchases compared with each fiscal year's allocation. Use the tooltip or table for small values."
    controls={<ChartKey />}
    table={<table><caption>{title}</caption><thead><tr>
      <th scope="col">Fiscal year</th><th scope="col">Electronic</th><th scope="col">Physical</th><th scope="col">Other</th>
      <th scope="col">Total paid</th><th scope="col">Allocated</th><th scope="col">Paid as % of allocated</th>
      <th scope="col">Purchases source</th><th scope="col">Allocation source</th>
    </tr></thead><tbody>{rows.map((row) => <tr key={row.fiscalYearEnd}>
      <th scope="row">{row.fiscalYearEnd}</th><td>{displayAmount(row.electronic)}</td><td>{displayAmount(row.physical)}</td>
      <td>{displayAmount(row.other)}</td><td>{displayAmount(row.total)}</td><td>{displayAmount(row.allocated)}</td>
      <td>{row.paidPercent === null ? 'n/a' : `${row.paidPercent.toFixed(1)}%`}</td>
      <td>{sourceName(row.purchasesSource)}</td><td>{sourceName(row.allocationSource)}</td>
    </tr>)}</tbody></table>}>
    <SpendingBudgetPlot rows={rows} />
  </ChartCard>;
}
