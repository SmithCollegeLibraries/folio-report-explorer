import { useId } from 'react';
import { BarChart, Bar, Cell, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';
import type { VisualizationData } from '../../api/visualizations';
import { amount, ChartCard } from './ChartCard';

const share = (value: number | null) => value === null ? 'n/a' : `${value.toFixed(1)}%`;

const groups = [
  { key: 'electronic', name: 'Electronic', color: '#2563a0' },
  { key: 'physical', name: 'Physical', color: '#287e78' },
  { key: 'other', name: 'Other', color: '#b17b22' },
] as const;

export function PurchaseCharts({ data, materialYear, onMaterialYear }: {
  data: VisualizationData; materialYear: number | 'all' | '';
  onMaterialYear: (year: number | 'all') => void;
}) {
  const pickerId = useId();
  const purchases = data.purchasesByCategory.filter((row) => row.total > 0);
  const materials = data.purchasesByMaterial.items.map((row) => ({
    ...row, materialType: row.materialType || 'No material type',
  })).sort((a, b) => b.amount - a.amount);
  const disabled = data.materialFiscalYearEnds.length === 0;
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
        <ResponsiveContainer width="100%" height="100%"><BarChart data={purchases}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="fiscalYearEnd" /><YAxis domain={[0, 100]} tickFormatter={(value) => `${value}%`} width={48} />
          <Tooltip content={({ active, payload, label }) => {
            if (!active || !payload?.length) return null;
            const row = payload[0].payload as VisualizationData['purchasesByCategory'][number];
            return <div className="bg-white border rounded p-3 shadow text-sm"><strong>FY {label}</strong>
              {groups.map((group) => <p key={group.key}>{group.name}: {amount(row[group.key])} ({share(row[`${group.key}Pct`])})</p>)}
              <p>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</p></div>;
          }} />
          <Legend />
          {groups.map((group) => <Bar key={group.key} dataKey={`${group.key}Pct`} name={group.name}
            stackId="purchases" fill={group.color} isAnimationActive={false}>
            {purchases.map((row) => <Cell key={row.fiscalYearEnd} stroke={row.source === 'history' ? '#172f44' : undefined}
              strokeWidth={2} strokeDasharray={row.source === 'history' ? '4 3' : undefined} />)}
          </Bar>)}
        </BarChart></ResponsiveContainer>
      </div>
    </ChartCard>
    <ChartCard title={materialTitle} explanation="Paid FOLIO purchases by material type, ordered by amount."
      controls={<div className="mb-4"><label htmlFor={pickerId} className="block text-sm font-medium mb-1">Material fiscal year</label>
        <select id={pickerId} value={disabled ? '' : materialYear} disabled={disabled}
          onChange={(event) => onMaterialYear(event.target.value === 'all' ? 'all' : Number(event.target.value))}
          className="border rounded px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-500">
          {disabled ? <option value="">No FOLIO years</option> : <>
            {Array.from(new Set([...(data.currentFiscalYearEnd === null ? [] : [data.currentFiscalYearEnd]), ...data.materialFiscalYearEnds]))
              .sort((a, b) => b - a).map((year) => <option key={year} value={year}>{year}</option>)}
            <option value="all">All FOLIO years</option>
          </>}
        </select></div>}
      table={<table><caption>{materialTitle}</caption><thead><tr><th scope="col">Material type</th><th scope="col">Amount</th></tr></thead>
        <tbody>{materials.map((row) => <tr key={row.materialType}><th scope="row">{row.materialType}</th><td>{amount(row.amount)}</td></tr>)}</tbody></table>}>
      {disabled ? <p className="py-16 text-center text-gray-600">No FOLIO purchase data for this unit</p>
        : materials.length === 0 ? <p className="py-16 text-center text-gray-600">No FOLIO purchases for this fiscal year</p>
          : <div style={{ height: Math.max(320, materials.length * 36) }}>
            <ResponsiveContainer width="100%" height="100%"><BarChart data={materials} layout="vertical" margin={{ right: 24 }}>
              <CartesianGrid strokeDasharray="3 3" horizontal={false} />
              <XAxis type="number" tickFormatter={(value) => Number(value).toLocaleString('en-US')} />
              <YAxis type="category" dataKey="materialType" width={110} tick={{ fontSize: 12 }} />
              <Tooltip formatter={(value) => [amount(Number(value)), 'Amount']} />
              <Bar dataKey="amount" name="Amount" fill="#2563a0" isAnimationActive={false} />
            </BarChart></ResponsiveContainer>
          </div>}
    </ChartCard>
  </>;
}
