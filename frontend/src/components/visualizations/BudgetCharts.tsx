import { BarChart, Bar, Cell, LabelList, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import type { VisualizationData } from '../../api/visualizations';
import { amount, percent, ChartCard } from './ChartCard';

export function BudgetCharts({ data }: { data: VisualizationData }) {
  return <>{[false, true].map((change) => {
    const title = change ? 'Library budget change' : 'Library budget';
    const rows = change ? data.budget.filter((row) => row.changePct !== null) : data.budget;
    return <ChartCard key={title} title={title} history
      explanation={change ? 'Change from the immediately previous fiscal year; missing or zero baselines are n/a.' : 'Total budget allocated to the acquisitions unit in each fiscal year.'}
      table={<table><caption>{title}</caption><thead><tr><th scope="col">Fiscal year</th>
        <th scope="col">Allocated</th>{change && <th scope="col">Change</th>}<th scope="col">Source</th>
      </tr></thead><tbody>{data.budget.map((row) => <tr key={row.fiscalYearEnd}>
        <th scope="row">{row.fiscalYearEnd}</th><td>{amount(row.allocated)}</td>
        {change && <td>{percent(row.changePct)}</td>}<td>{row.source === 'history' ? 'Stored history' : 'FOLIO'}</td>
      </tr>)}</tbody></table>}>
      <div className="h-80"><ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} margin={{ top: 28, right: 12, bottom: 12 }}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="fiscalYearEnd" />
          <YAxis width={70} tickFormatter={(value) => change ? `${value}%` : Number(value).toLocaleString('en-US')} />
          <Tooltip formatter={(value) => [change ? percent(Number(value)) : amount(Number(value)), change ? 'Change' : 'Allocated']} />
          <Bar dataKey={change ? 'changePct' : 'allocated'} name={change ? 'Change' : 'Allocated'} fill="#2563a0" isAnimationActive={false}>
            {rows.map((row) => <Cell key={row.fiscalYearEnd}
              fill={change ? (row.changePct! >= 0 ? '#287e78' : '#b9443c') : '#2563a0'}
              stroke={row.source === 'history' ? '#172f44' : undefined} strokeWidth={2}
              strokeDasharray={row.source === 'history' ? '4 3' : undefined} />)}
            {change && <LabelList dataKey="changePct" position="top" formatter={(value) => percent(Number(value))} style={{ fontSize: 12, fill: '#374151' }} />}
          </Bar>
        </BarChart>
      </ResponsiveContainer></div>
    </ChartCard>;
  })}</>;
}
