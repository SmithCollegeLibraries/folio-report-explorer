import { useId } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, Legend, XAxis, YAxis } from 'recharts';
import type { VisualizationData } from '../../api/visualizations';
import { ChartContainer, type ChartConfig } from '../ui/chart';
import { ChartLegendContent } from '../ui/chart-legend';
import { ChartTooltip } from '../ui/chart-tooltip';
import { ChartCard, HistoryPattern, amount } from './ChartCard';
import { MaterialPieChart, materialRows } from './MaterialPieChart';
import { errorMessage } from '../../utils/errorMessage';

export const PURCHASE_MATERIAL_GROUPS = {
  electronic: ['Data File', 'Database', 'E-Book', 'E-Book Package', 'E-Journal', 'E-Journal Package', 'E-Newspaper', 'Streaming Video'],
  physical: ['Audio CD', 'Book', 'DVD/Blu-ray', 'Journal', 'Newspaper', 'Score', 'Serial'],
} as const;

const share = (value: number | null) => value === null ? 'n/a' : `${value.toFixed(1)}%`;
const groups = [
  { key: 'electronic', name: 'Electronic', color: 'var(--chart-1)' },
  { key: 'physical', name: 'Physical', color: 'var(--chart-2)' },
  { key: 'other', name: 'Other', color: 'var(--chart-3)' },
] as const;
const groupConfig: ChartConfig = Object.fromEntries(groups.map((group) => [group.key, {
  label: group.name, color: group.color,
}]));

export function PurchaseCharts({ data, materialYear, materialFiscalYearEnds, materialLoading, materialError, materialAvailable, onMaterialYear }: {
  data: VisualizationData; materialYear: number | 'all' | '';
  materialFiscalYearEnds: number[];
  materialLoading: boolean; materialError?: Error; materialAvailable: boolean;
  onMaterialYear: (year: number | 'all') => void;
}) {
  const pickerId = useId();
  const patternId = useId().replace(/:/g, '');
  const purchases = data.purchasesByCategory.filter((row) => row.total > 0);
  const materialItems = data.purchasesByMaterial.items;
  const materials = materialRows(materialItems);
  const disabled = materialFiscalYearEnds.length === 0;
  const title = 'How the library purchases';
  const materialTitle = 'How the library purchases by material';

  return <>
    <ChartCard title={title} explanation="Electronic, physical and other purchases as a share of each fiscal year's spending." history
      table={<table><caption>{title}</caption><thead><tr>
        <th scope="col">Fiscal year</th>{groups.map((group) => <th key={group.key} scope="col">{group.name} (amount / share)</th>)}
        <th scope="col">Total</th><th scope="col">Source</th>
      </tr></thead><tbody>{data.purchasesByCategory.map((row) => <tr key={row.fiscalYearEnd}>
        <th scope="row">{row.fiscalYearEnd}</th>{groups.map((group) => <td key={group.key}>
          {amount(row[group.key])} / {row.total > 0 ? share(row[`${group.key}Pct`]) : 'no spending'}
        </td>)}<td>{amount(row.total)}</td><td>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</td>
      </tr>)}</tbody></table>}>
      <div className="h-80" aria-label="Purchases as percentages by fiscal year">
        <ChartContainer config={groupConfig} className="h-full">
          <BarChart data={purchases} barCategoryGap={2} barGap={2}>
          <HistoryPattern id={patternId} />
          <CartesianGrid stroke="#e5e7eb" strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="fiscalYearEnd" tickLine={false} axisLine={false} />
          <YAxis domain={[0, 100]} tickFormatter={(value) => `${value}%`} width={48} tickLine={false} axisLine={false} />
          <ChartTooltip content={({ active, payload, label }) => {
            if (!active || !payload?.length) return null;
            const row = payload[0].payload as VisualizationData['purchasesByCategory'][number];
            return <div className="rounded-md border border-gray-200 bg-white p-3 text-sm shadow-md"><strong>FY {label}</strong>
              {groups.map((group) => <p key={group.key}>{group.name}: {amount(row[group.key])} ({share(row[`${group.key}Pct`])})</p>)}
              <p>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</p></div>;
          }} />
          <Legend content={<ChartLegendContent />} />
          {groups.map((group) => <Bar key={group.key} dataKey={`${group.key}Pct`} name={group.name}
            stackId="purchases" fill={group.color} radius={[4, 4, 0, 0]} isAnimationActive={false}>
            {purchases.map((row) => <Cell key={row.fiscalYearEnd}
              fill={row.source === 'history' ? `url(#${patternId})` : group.color} />)}
          </Bar>)}
          </BarChart>
        </ChartContainer>
        <svg className="recharts-surface pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 800 320" preserveAspectRatio="none" aria-hidden="true">
          <g className="recharts-label-list">
            {purchases.flatMap((row, index) => {
              const x = 64 + (index + 0.5) * (720 / Math.max(purchases.length, 1));
              let cumulative = 0;
              return groups.map((group) => {
                const value = row[`${group.key}Pct`] ?? 0;
                const y = 272 - (cumulative + value / 2) * 2.6;
                cumulative += value;
                return value >= 8 ? <text key={`${row.fiscalYearEnd}-${group.key}`} x={x} y={y + 4}
                  textAnchor="middle" fill={group.key === 'electronic' ? '#ffffff' : '#111827'} fontSize="11" fontWeight="600">
                  {Math.round(value)}%
                </text> : null;
              });
            })}
          </g>
        </svg>
      </div>
      <aside className="mt-3 rounded-md bg-gray-50 p-3 text-sm text-gray-700">
        <h3 className="font-semibold text-gray-900">What's in each group</h3>
        <p><strong>Electronic:</strong> {PURCHASE_MATERIAL_GROUPS.electronic.join(', ')}</p>
        <p><strong>Physical:</strong> {PURCHASE_MATERIAL_GROUPS.physical.join(', ')}</p>
        <p><strong>Other:</strong> every other material type and purchases without one.</p>
      </aside>
    </ChartCard>
    <ChartCard title={materialTitle} explanation="Paid FOLIO purchases by material type; slices show positive amounts, and the table retains every value."
      controls={<div className="mb-4"><label htmlFor={pickerId} className="mb-1 block text-sm font-medium">Material fiscal year</label>
        <select id={pickerId} value={disabled ? '' : materialYear} disabled={disabled}
          onChange={(event) => onMaterialYear(event.target.value === 'all' ? 'all' : Number(event.target.value))}
          className="rounded border border-gray-300 bg-white px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-500">
          {disabled ? <option value="">No FOLIO years</option> : <>
            {Array.from(new Set([...(data.currentFiscalYearEnd === null ? [] : [data.currentFiscalYearEnd]), ...materialFiscalYearEnds]))
              .sort((a, b) => b - a).map((year) => <option key={year} value={year}>{year}</option>)}
            <option value="all">All FOLIO years</option>
          </>}
        </select></div>}
      table={<table><caption>{materialTitle}: amount and share</caption><thead><tr>
        <th scope="col">Material type</th><th scope="col">Amount</th><th scope="col">Percent of positive purchases</th>
      </tr></thead><tbody>{materials.map((row, index) => <tr key={`${row.name}-${index}`}>
        <th scope="row">{row.name}</th><td>{amount(row.amount)}</td><td>{row.percentage.toFixed(1)}%</td>
      </tr>)}</tbody></table>}>
      {materialLoading ? <p role="status" className="py-16 text-center text-gray-600">Loading material purchases…</p>
        : materialError ? <p role="status" className="py-16 text-center text-red-800">
          {errorMessage(materialError)} Use Retry above to try again.
        </p>
          : !materialAvailable ? <p role="status" className="py-16 text-center text-gray-600">Material purchase data is unavailable</p>
            : disabled ? <p className="py-16 text-center text-gray-600">No FOLIO purchase data for this unit</p>
              : materials.length === 0 ? <p className="py-16 text-center text-gray-600">No FOLIO purchases for this fiscal year</p>
                : <MaterialPieChart items={materialItems} />}
    </ChartCard>
  </>;
}
