import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { SpendingPaceData } from '../../api/visualizations';
import { errorMessage } from '../../utils/errorMessage';
import { amount, ChartCard } from './ChartCard';

const PAYMENT_DATE_NOTE = 'Counts payments by the date they were paid, so totals can differ from the purchases charts, which count by invoice year.';
const reasons = {
  no_current_fy: 'No fiscal year in FOLIO covers today',
  no_series: 'No fiscal year series in FOLIO',
  no_funds: 'No funds belong to this acquisitions unit',
};

export function SpendingPaceChart({ data, isPending, error, onRetry }: {
  data?: SpendingPaceData;
  isPending: boolean;
  error?: unknown;
  onRetry: () => unknown;
}) {
  const currentEnd = data?.currentFiscalYearEnd;
  const previousEnd = data?.previousFiscalYearEnd;
  const paidSoFar = data?.months.reduce((latest, month) => month.currentCumulative === null ? latest : month.currentCumulative, 0) ?? 0;
  const currentLabel = currentEnd === null || currentEnd === undefined ? 'This fiscal year' : `FY${currentEnd} (this FY)`;
  const previousLabel = previousEnd === null ? 'No previous year in FOLIO' : `FY${previousEnd} (last FY)`;
  const summary = data?.available
    ? `Paid so far $${amount(paidSoFar)} · Open encumbrances $${amount(data.openEncumbrances ?? 0)} · Allocated ${data.allocated.amount === null ? 'not available' : `$${amount(data.allocated.amount)} (${data.allocated.source === 'folio' ? 'FOLIO' : 'history'})`}`
    : null;

  const table = data?.available ? <table>
    <caption>Monthly cumulative paid spending for FY{currentEnd} and {previousEnd === null ? 'no previous fiscal year' : `FY${previousEnd}`}.</caption>
    <thead><tr><th scope="col">Month</th><th scope="col">FY{currentEnd}</th>
      <th scope="col">{previousEnd === null ? 'No previous year in FOLIO' : `FY${previousEnd}`}</th></tr></thead>
    <tbody>{data.months.map((month) => <tr key={month.fiscalMonth}>
      <th scope="row">{month.label}</th>
      <td>{month.currentCumulative === null ? 'not yet' : amount(month.currentCumulative)}</td>
      <td>{month.previousCumulative === null ? (previousEnd === null ? '—' : 'not yet') : amount(month.previousCumulative)}</td>
    </tr>)}</tbody>
  </table> : <table><caption>Spending pace is unavailable for this acquisitions unit.</caption><thead><tr><th scope="col">Status</th></tr></thead>
    <tbody><tr><td>{data?.reason ? reasons[data.reason] : 'No spending pace data.'}</td></tr></tbody></table>;

  return <ChartCard title="Spending pace this year" explanation={PAYMENT_DATE_NOTE} table={table}>
    {isPending && <p role="status" aria-live="polite">Loading spending pace…</p>}
    {Boolean(error) && <div role="alert" className="text-sm text-red-800">
      <p>{errorMessage(error)}</p><button type="button" onClick={() => { void onRetry(); }}
        className="underline font-medium mt-2">Retry spending pace</button>
    </div>}
    {data && !data.available && <>
      <p className="text-sm font-medium text-gray-800">{reasons[data.reason ?? 'no_current_fy']}</p>
      {data.allocated.amount !== null && <p className="mt-2 text-sm">Allocated ${amount(data.allocated.amount)} ({data.allocated.source === 'folio' ? 'FOLIO' : 'history'})</p>}
    </>}
    {data?.available && <>
      <p className="mb-3 text-sm font-medium text-gray-800">{summary}</p>
      <div aria-label="Spending line labels" className="mb-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-700">
        <span className="inline-flex items-center gap-2"><svg aria-hidden="true" width="28" height="12">
          <line x1="1" y1="6" x2="27" y2="6" stroke="#135f82" strokeWidth="2" />
          <circle cx="14" cy="6" r="3" fill="white" stroke="#135f82" strokeWidth="2" />
        </svg>{currentLabel}</span>
        <span className="inline-flex items-center gap-2"><svg aria-hidden="true" width="28" height="12">
          <line x1="1" y1="6" x2="27" y2="6" stroke="#92400e" strokeWidth="2" strokeDasharray="6 4" />
          <circle cx="14" cy="6" r="3" fill="white" stroke="#92400e" strokeWidth="2" />
        </svg>{previousLabel}</span>
      </div>
      <div role="img" aria-label={`Monthly cumulative spending for FY${currentEnd} and ${previousEnd === null ? 'no previous year in FOLIO' : `FY${previousEnd}`}`} className="h-72 min-w-0">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data.months} margin={{ top: 8, right: 16, bottom: 4, left: 8 }}>
            <CartesianGrid strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="label" tick={{ fontSize: 12 }} />
            <YAxis tickFormatter={(value: number) => `$${value}`} width={68} />
            <Tooltip formatter={(value: number | string | undefined) => value === undefined ? '' : `$${amount(Number(value))}`} />
            <Line type="monotone" dataKey="currentCumulative" name={currentLabel} stroke="#135f82"
              strokeWidth={2.5} dot={{ r: 3, strokeWidth: 2 }} activeDot={{ r: 5 }} connectNulls={false} />
            <Line type="monotone" dataKey="previousCumulative" name={previousLabel} stroke="#92400e"
              strokeWidth={2.5} strokeDasharray="6 4" dot={{ r: 3, strokeWidth: 2 }} activeDot={{ r: 5 }} connectNulls={false} />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </>}
  </ChartCard>;
}
