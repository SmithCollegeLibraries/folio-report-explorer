import { useId } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, Legend, XAxis, YAxis } from 'recharts';
import type { VisualizationData } from '../../api/visualizations';
import { ChartContainer, type ChartConfig } from '../ui/chart';
import { ChartLegendContent } from '../ui/chart-legend';
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

export function BudgetCharts({ data }: { data: VisualizationData }) {
  const patternId = useId().replace(/:/g, '');
  const narrow = useNarrowViewport();
  const crowded = data.budget.length >= 15 || narrow;
  const title = 'Library budget';
  return <ChartCard title={title} history explanation="Total budget allocated to the acquisitions unit, with the API-reported year-over-year change."
    table={<table><caption>{title}</caption><thead><tr><th scope="col">Fiscal year</th>
      <th scope="col">Allocated</th><th scope="col">Change</th><th scope="col">Source</th>
    </tr></thead><tbody>{data.budget.map((row) => <tr key={row.fiscalYearEnd}>
      <th scope="row">{row.fiscalYearEnd}</th><td>{amount(row.allocated)}</td><td>{percent(row.changePct)}</td>
      <td>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</td>
    </tr>)}</tbody></table>}>
    <div className="relative h-80" aria-label="Allocated budget by fiscal year">
      <ChartContainer config={config} className="h-full">
        <BarChart data={data.budget} margin={{ top: 42, right: 12, bottom: 12, left: 72 }} barCategoryGap={2}>
        <HistoryPattern id={patternId} />
        <CartesianGrid stroke="#e5e7eb" strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="fiscalYearEnd" hide />
        <YAxis hide />
        <ChartTooltip content={<ChartTooltipContent hideLabel indicator="line" formatter={(_value, _name, item) => {
          const row = item.payload as BudgetRow;
          return <div className="grid gap-1.5 text-sm">
            <strong>FY {row.fiscalYearEnd}</strong><p>Allocated: {amount(row.allocated)}</p>
            <p>Change: {changeLabel(row.changePct)}</p><p>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</p>
          </div>;
        }} />} />
        <Legend content={<ChartLegendContent />} />
        <Bar dataKey="allocated" name="Allocated" fill="var(--chart-1)" radius={[4, 4, 0, 0]} isAnimationActive={false}>
          {data.budget.map((row) => <Cell key={row.fiscalYearEnd}
            fill={row.source === 'history' ? `url(#${patternId})` : 'var(--chart-1)'} />)}
        </Bar>
        </BarChart>
      </ChartContainer>
      <svg className="recharts-surface pointer-events-none absolute inset-0 z-10 h-full w-full" viewBox="0 0 800 320" preserveAspectRatio="none" aria-hidden="true">
        <g className="recharts-yAxis" textAnchor="end" fill="#6b7280" fontSize="11">
          {[0, 1, 2, 3, 4].map((step) => {
            const max = Math.max(...data.budget.map((row) => row.allocated), 1);
            const value = max * step / 4;
            return <text key={step} className="recharts-cartesian-axis-tick-value" x="74" y={274 - step * 54}>{compactAmount(value)}</text>;
          })}
        </g>
        <g className="recharts-xAxis" textAnchor="middle" fill="#6b7280" fontSize="11">
          {data.budget.map((row, index) => {
            const x = 88 + (index + 0.5) * (696 / Math.max(data.budget.length, 1));
            return <text key={row.fiscalYearEnd} className="recharts-cartesian-axis-tick-value" x={x} y="306">{row.fiscalYearEnd}</text>;
          })}
        </g>
        <g className="recharts-label-list" textAnchor="middle" fill="#374151" fontSize="11">
          {data.budget.map((row, index) => {
            const x = 88 + (index + 0.5) * (696 / Math.max(data.budget.length, 1));
            return <text key={row.fiscalYearEnd} x={x} y="24" fontSize={crowded ? 9 : 11}>
              <tspan x={x}>{compactAmount(row.allocated)}</tspan>
              {!crowded && <tspan x={x} dy="13">{changeLabel(row.changePct)}</tspan>}
            </text>;
          })}
        </g>
      </svg>
    </div>
  </ChartCard>;
}
