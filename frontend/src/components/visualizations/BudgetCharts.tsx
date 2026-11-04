import { useId } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, LabelList, XAxis, YAxis } from 'recharts';
import type { VisualizationData } from '../../api/visualizations';
import { ChartContainer, type ChartConfig } from '../ui/chart';
import { ChartTooltip, ChartTooltipContent } from '../ui/chart-tooltip';
import { ChartCard, HistoryPattern, amount, percent } from './ChartCard';
import { useNarrowViewport } from './useNarrowViewport';

type BudgetRow = VisualizationData['budget'][number];
const config: ChartConfig = { allocated: { label: 'Allocated', color: 'var(--chart-1)' } };

function compactAmount(value: number) {
  if (value >= 1_000_000) return `$${(value / 1_000_000).toFixed(2)}M`;
  if (value >= 10_000) return `$${Math.round(value / 1000)}k`;
  if (value >= 1000) return `$${(value / 1000).toFixed(1)}k`;
  return `$${Math.round(value).toLocaleString('en-US')}`;
}

function changeLabel(value: number | null) {
  if (value === null) return 'n/a';
  if (value > 0) return `▲ ${value.toFixed(1)}%`;
  if (value < 0) return `▼ ${Math.abs(value).toFixed(1)}%`;
  return '– 0.0%';
}

function estimatedLabelWidth(value: string) {
  return Array.from(value).reduce((width, character) => width + (
    /[0-9$%k]/.test(character) ? 7
      : character === 'M' ? 8
        : character === '▲' || character === '▼' ? 10
          : character === ' ' || character === '.' ? 4 : 7
  ), 0);
}

export function BudgetCharts({ data }: { data: VisualizationData }) {
  const patternId = useId().replace(/:/g, '');
  const narrow = useNarrowViewport();
  const showChangeLabels = !narrow && data.budget.length < 15;
  const defaultTopMargin = showChangeLabels && data.budget.length >= 10 ? 112 : 42;
  const longestAmountLabel = data.budget.reduce((longest, row) =>
    Math.max(longest, estimatedLabelWidth(compactAmount(row.allocated))), 0);
  const topMargin = Math.max(defaultTopMargin, longestAmountLabel + 10);
  const title = 'Library budget';
  return <ChartCard title={title} history explanation="Total budget allocated to the acquisitions unit, with the API-reported year-over-year change."
    table={<table><caption>{title}</caption><thead><tr><th scope="col">Fiscal year</th>
      <th scope="col">Allocated</th><th scope="col">Change</th><th scope="col">Source</th>
    </tr></thead><tbody>{data.budget.map((row) => <tr key={row.fiscalYearEnd}>
      <th scope="row">{row.fiscalYearEnd}</th><td>{amount(row.allocated)}</td><td>{percent(row.changePct)}</td>
      <td>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</td>
    </tr>)}</tbody></table>}>
    <div className="relative h-80 min-w-0 w-full" aria-label="Allocated budget by fiscal year">
      <ChartContainer config={config} className="h-full min-w-0 w-full">
        <BarChart data={data.budget} margin={{ top: topMargin, right: 12, bottom: 12, left: 8 }} barCategoryGap={2}>
        <HistoryPattern id={patternId} />
        <CartesianGrid stroke="#e5e7eb" strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="fiscalYearEnd" tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
        <YAxis width={72} domain={[0, 'auto']} tickFormatter={compactAmount}
          tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
        <ChartTooltip content={<ChartTooltipContent hideLabel indicator="line" formatter={(_value, _name, item) => {
          const row = item.payload as BudgetRow;
          return <div className="grid gap-1.5 text-sm">
            <strong>FY {row.fiscalYearEnd}</strong><p>Allocated: {amount(row.allocated)}</p>
            <p>Change: {changeLabel(row.changePct)}</p><p>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</p>
          </div>;
        }} />} />
        <Bar dataKey="allocated" name="Allocated" fill="var(--chart-1)" radius={[4, 4, 0, 0]} isAnimationActive={false}>
          {data.budget.map((row) => <Cell key={row.fiscalYearEnd}
            fill={row.source === 'history' ? `url(#${patternId})` : 'var(--chart-1)'} />)}
          <LabelList dataKey="allocated" position="top" content={({ x, y, width, index }) => {
            const barIndex = Number(index);
            const row = data.budget[barIndex];
            if (!row) return null;
            const labelX = Number(x ?? 0) + Number(width ?? 0) / 2;
            const labelY = Number(y ?? 0);
            const barWidth = Number(width ?? 0);
            const amountText = compactAmount(row.allocated);
            const changeText = changeLabel(row.changePct);
            const amountWidth = estimatedLabelWidth(amountText);
            const changeWidth = estimatedLabelWidth(changeText);
            const horizontal = amountWidth + 8 <= barWidth
              && (!showChangeLabels || changeWidth + 8 <= barWidth);
            const labelYAboveBar = labelY - 6;

            if (!horizontal) {
              return <text x={labelX} y={labelYAboveBar}
                transform={`rotate(-90 ${labelX} ${labelYAboveBar})`}
                textAnchor="start" fill="#374151" fontSize={9}>
                <tspan x={labelX}>{amountText}</tspan>
                {showChangeLabels && <tspan x={labelX} dy="10">{changeText}</tspan>}
              </text>;
            }

            return <text x={labelX} y={labelY - (showChangeLabels ? 20 : 7)} textAnchor="middle" fill="#374151" fontSize={11}>
              <tspan x={labelX}>{amountText}</tspan>
              {showChangeLabels && <tspan x={labelX} dy="13">{changeText}</tspan>}
            </text>;
          }} />
        </Bar>
        </BarChart>
      </ChartContainer>
    </div>
  </ChartCard>;
}
