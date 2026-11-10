import { useId } from 'react';
import {
  Bar, BarChart, CartesianGrid, LabelList, Legend, Line, LineChart, XAxis, YAxis,
} from 'recharts';
import type { ChartConfig } from '../ui/chart';
import { ChartContainer } from '../ui/chart';
import { ChartTooltip, ChartTooltipContent } from '../ui/chart-tooltip';
import { ChartLegendContent } from '../ui/chart-legend';
import { ChartCard } from './ChartCard';
import { useChartLabelSize } from './ChartLabelSize';
import { useGateCounts } from '../../hooks/useGateCounts';
import type { GateCountFiscalYear, GateCountResponse } from '../../types/gateCounts';

const MONTHS = ['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function fiscalMonth(index: number) {
  return index < 6 ? index + 7 : index - 5;
}

function monthTableLabel(month: string, currentMonth: string | undefined) {
  const date = new Date(month + '-01T12:00:00Z');
  const label = date.toLocaleDateString('en-US', { month: 'long', timeZone: 'UTC' });
  return month === currentMonth ? label + ' (to date)' : label;
}

function monthlyChartData(data: GateCountResponse) {
  const series = data.monthlyByFiscalYear;
  const latestMonths = series[series.length - 1]?.months;
  const currentMonth = latestMonths?.[latestMonths.length - 1]?.month;
  const points = MONTHS.map((label, index) => {
    const month = String(fiscalMonth(index)).padStart(2, '0');
    const point: Record<string, string | number | null> = {
      monthIndex: index,
      monthLabel: currentMonth?.slice(5) === month ? label + ' (to date)' : label,
    };
    for (const year of series) {
      const value = year.months.find((entry) => entry.month.slice(5) === month);
      point['fy' + year.fiscalYearEnd] = value ? value.visits : null;
    }
    return point;
  });
  const chartConfig: ChartConfig = Object.fromEntries(series.map((year, index) => [
    'fy' + year.fiscalYearEnd,
    { label: 'FY ' + year.fiscalYearEnd, color: COLORS[index] },
  ]));
  return { points, series, currentMonth, chartConfig };
}

function fiscalYearTable(years: GateCountFiscalYear[], currentMonth: string | undefined) {
  return <table><caption>Monthly visits by fiscal year</caption><thead><tr>
    <th scope="col">Fiscal year</th><th scope="col">Month</th><th scope="col">Visits</th>
  </tr></thead><tbody>{years.flatMap((year) => year.months.map((month) =>
    <tr key={String(year.fiscalYearEnd) + '-' + month.month}>
      <th scope="row">FY {year.fiscalYearEnd}</th>
      <td>{monthTableLabel(month.month, currentMonth)}</td><td>{month.visits.toLocaleString('en-US')}</td>
    </tr>))}</tbody></table>;
}

function MonthlyVisitsChart({ data }: { data: GateCountResponse }) {
  const labelSize = useChartLabelSize();
  const chart = monthlyChartData(data);
  return <ChartCard title="Monthly visits by fiscal year"
    explanation="Entries by Eastern month across Smith fiscal years, July through June."
    table={fiscalYearTable(chart.series, chart.currentMonth)}>
    <ChartContainer config={chart.chartConfig} className="h-80 min-w-0 w-full" aria-label="Monthly visits by fiscal year">
      <LineChart data={chart.points} margin={{ top: 12, right: 60, bottom: 4, left: 4 }}>
        <CartesianGrid stroke="#e5e7eb" strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="monthLabel" interval={0} tick={{ fontSize: labelSize }} tickLine={false} axisLine={false} />
        <YAxis allowDecimals={false} width={54} tick={{ fontSize: labelSize }} tickLine={false} axisLine={false} />
        <ChartTooltip content={<ChartTooltipContent formatter={(value) => Number(value).toLocaleString('en-US')} />} />
        <Legend content={<ChartLegendContent className={labelSize === 18 ? 'text-[18px]' : 'text-[13px]'} />} />
        {chart.series.map((year, index) => {
          const key = 'fy' + year.fiscalYearEnd;
          const lastIndex = chart.points.reduce((last, point, pointIndex) =>
            typeof point[key] === 'number' ? pointIndex : last, -1);
          return <Line key={key} type="monotone" dataKey={key} name={'FY ' + year.fiscalYearEnd}
            stroke={COLORS[index]} strokeWidth={2} dot={false} connectNulls={false} isAnimationActive={false}>
            {chart.series.length <= 4 && <LabelList dataKey={key} content={(props) => {
              const indexAtPoint = Number(props.index);
              if (indexAtPoint !== lastIndex) return null;
              return <text x={Number(props.x) + 6} y={Number(props.y)} fill={COLORS[index]}
                fontSize={labelSize} dominantBaseline="middle">FY {year.fiscalYearEnd}</text>;
            }} />}
          </Line>;
        })}
      </LineChart>
    </ChartContainer>
  </ChartCard>;
}

function visitsTable(caption: string, rows: { date: string; visits: number }[]) {
  return <table><caption>{caption}</caption><thead><tr>
    <th scope="col">Date</th><th scope="col">Visits</th>
  </tr></thead><tbody>{rows.map((row) => <tr key={row.date}>
    <th scope="row">{row.date}</th><td>{row.visits.toLocaleString('en-US')}</td>
  </tr>)}</tbody></table>;
}

type GateTooltipRow = { dataKey?: string; name?: string; value?: number | string | null; color?: string };

function GateCountTooltip({ active, payload, label }: {
  active?: boolean; payload?: GateTooltipRow[]; label?: string | number;
}) {
  if (!active || !payload?.length) return null;
  return <div className="rounded-md border border-gray-200 bg-white p-3 text-sm shadow-md">
    <p className="font-medium">{label}</p>
    {payload.filter((item) => item.value !== null && item.value !== undefined).map((item) =>
      <p key={item.dataKey} className="flex items-center gap-2">
        <span aria-hidden="true" className="h-2 w-2 rounded-full" style={{ backgroundColor: item.color }} />
        {item.name || item.dataKey}: {Number(item.value).toLocaleString('en-US')}
      </p>)}
  </div>;
}

function DailyVisitsChart({ data }: { data: GateCountResponse }) {
  const labelSize = useChartLabelSize();
  return <ChartCard title="Daily visits" explanation="Entries by Eastern date for the selected period."
    table={visitsTable('Daily visits', data.daily)}>
    <ChartContainer config={{ visits: { label: 'Visits', color: 'var(--chart-1)' } }}
      className="h-80 min-w-0 w-full" aria-label="Daily visits">
      <LineChart data={data.daily} margin={{ top: 10, right: 10, bottom: 8, left: 4 }}>
        <CartesianGrid stroke="#e5e7eb" strokeDasharray="3 3" vertical={false} />
        <XAxis dataKey="date" interval="preserveStartEnd" tickFormatter={(date) => String(date).slice(5)}
          tick={{ fontSize: labelSize }} tickLine={false} axisLine={false} />
        <YAxis allowDecimals={false} width={54} tick={{ fontSize: labelSize }} tickLine={false} axisLine={false} />
        <ChartTooltip content={<GateCountTooltip />} />
        <Line type="monotone" dataKey="visits" name="Visits" stroke="var(--chart-1)"
          strokeWidth={2} dot={false} isAnimationActive={false} />
      </LineChart>
    </ChartContainer>
  </ChartCard>;
}

function weekdayDateCounts(start: string, end: string): number[] {
  const counts = Array<number>(7).fill(0);
  const date = new Date(start + 'T00:00:00Z');
  const last = new Date(end + 'T00:00:00Z');
  while (date <= last) {
    counts[date.getUTCDay()] += 1;
    date.setUTCDate(date.getUTCDate() + 1);
  }
  return counts;
}

function displayAverage(value: number | null): string {
  if (value === null) return 'no days';
  if (value > 0 && value < 0.5) return '<1';
  return Math.round(value).toLocaleString('en-US');
}

type HeatmapProps = { data: GateCountResponse['heatmap']; period: GateCountResponse['period'] };

function Heatmap({ data, period }: HeatmapProps) {
  const labelSize = useChartLabelSize();
  const dayCounts = weekdayDateCounts(period.start, period.end);
  const visits = (weekday: number, hour: number) => data.find((item) =>
    item.weekday === weekday && item.hour === hour)?.visits ?? 0;
  const average = (weekday: number, hour: number) => dayCounts[weekday]
    ? visits(weekday, hour) / dayCounts[weekday] : null;
  const maximum = Math.max(0, ...data.map((cell) => dayCounts[cell.weekday]
    ? cell.visits / dayCounts[cell.weekday] : 0));
  const cellTable = <table><caption>Busiest hours (average entries per day)</caption><thead><tr>
    <th scope="col">Weekday</th>{Array.from({ length: 24 }, (_, hour) =>
      <th key={hour} scope="col">{hour}:00</th>)}
  </tr></thead><tbody>{WEEKDAYS.map((weekday, day) => <tr key={weekday}>
    <th scope="row">{weekday}</th>{Array.from({ length: 24 }, (_, hour) =>
      <td key={hour}>{displayAverage(average(day, hour))}</td>)}
  </tr>)}</tbody><tfoot><tr><td colSpan={25} className="pt-2 text-left text-sm">
    Days in period: {WEEKDAYS.map((weekday, day) => weekday + ': ' + dayCounts[day]).join(', ')}.
  </td></tr></tfoot></table>;

  return <ChartCard title="Busiest hours (average per day)"
    explanation="Average entries per day by Eastern weekday and hour, over the selected period."
    table={cellTable}>
    <div role="group" aria-label="Busiest hours heatmap" className="overflow-x-auto">
      <div className="min-w-[720px]">
        <div style={{ fontSize: labelSize }} className="grid grid-cols-[5.25rem_repeat(24,minmax(0,1fr))] gap-1 mb-1 text-center text-gray-500"
          aria-hidden="true">
          <span />
          {Array.from({ length: 24 }, (_, hour) => <span key={hour}>{hour % 3 === 0 ? hour : ''}</span>)}
        </div>
        {WEEKDAYS.map((weekday, day) => <div key={weekday}
          className="grid grid-cols-[5.25rem_repeat(24,minmax(0,1fr))] gap-1 items-center mb-1">
          <span style={{ fontSize: labelSize }} className="text-gray-700">{weekday.slice(0, 3)}</span>
          {Array.from({ length: 24 }, (_, hour) => {
            const value = average(day, hour);
            const total = visits(day, hour);
            const display = displayAverage(value);
            const days = dayCounts[day];
            const time = `${hour}:00-${(hour + 1) % 24}:00`;
            const countLabel = display === '1' || display === '<1' ? 'entry' : 'entries';
            const weekdayLabel = days === 1 ? weekday : weekday + 's';
            const detail = days === 0 ? `${weekday} ${time}: no days`
              : `${weekday} ${time}: about ${display} ${countLabel} on an average ${weekday} (${total.toLocaleString('en-US')} over ${days} ${weekdayLabel})`;
            const opacity = value ? 0.15 + (value / maximum) * 0.85 : 0;
            return <button key={hour} type="button" aria-label={detail} title={detail}
              className="group relative aspect-square min-w-0 rounded-[2px] border border-slate-200 focus-visible:z-10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-blue-800"
              style={{ backgroundColor: value === null || value === 0 ? '#f3f4f6' : 'rgba(0, 114, 178, ' + opacity + ')' }}>
              <span role="tooltip" className="pointer-events-none invisible absolute bottom-full left-1/2 z-20 mb-1 -translate-x-1/2 whitespace-nowrap rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-900 shadow group-hover:visible group-focus:visible">
                {detail}
              </span>
            </button>;
          })}
        </div>)}
      </div>
    </div>
    <div className="mt-3 flex items-center gap-2 text-xs text-gray-600" aria-label="Heatmap legend">
      <span>Fewer</span><span aria-hidden="true" className="h-3 w-40 rounded-sm border border-gray-200"
        style={{ background: 'linear-gradient(to right, #f3f4f6, rgba(0,114,178,.2), rgba(0,114,178,.55), #0072b2)' }} />
      <span>More entries</span>
    </div>
  </ChartCard>;
}

function TopDaysChart({ data }: { data: GateCountResponse['topDays'] }) {
  const labelSize = useChartLabelSize();
  const days = [...data].sort((a, b) => b.visits - a.visits);
  const chartData = days.map((day) => ({ ...day, label: new Date(day.date + 'T12:00:00Z').toLocaleDateString('en-US', {
    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC',
  }) }));
  const table = <table><caption>Top 10 busiest days</caption><thead><tr>
    <th scope="col">Rank</th><th scope="col">Date</th><th scope="col" className="text-right">Visits</th>
  </tr></thead><tbody>{chartData.map((day, index) => <tr key={day.date}>
    <th scope="row">{index + 1}</th><td>{day.label}</td>
    <td className="text-right tabular-nums">{day.visits.toLocaleString('en-US')}</td>
  </tr>)}</tbody></table>;
  return <ChartCard title="Top 10 busiest days" explanation="The busiest days with at least one entry, ordered by visits."
    table={table}>{chartData.length === 0 ? <p role="status" className="text-sm text-gray-600">No days with visits in this period.</p> :
      <ChartContainer config={{ visits: { label: 'Visits', color: 'var(--chart-1)' } }}
        className="h-80 min-w-0 w-full" aria-label="Top 10 busiest days">
        <BarChart data={chartData} layout="vertical" margin={{ top: 8, right: 40, bottom: 8, left: 8 }}>
          <CartesianGrid stroke="#e5e7eb" strokeDasharray="3 3" horizontal={false} />
          <XAxis type="number" allowDecimals={false} tick={{ fontSize: labelSize }} tickLine={false} axisLine={false} />
          <YAxis type="category" dataKey="label" width={172} interval={0}
            tick={{ fontSize: labelSize }} tickLine={false} axisLine={false} />
          <ChartTooltip content={<ChartTooltipContent />} />
          <Bar dataKey="visits" name="Visits" fill="var(--chart-1)" radius={[0, 4, 4, 0]} isAnimationActive={false}>
            <LabelList dataKey="visits" position="right" formatter={(value) => Number(value).toLocaleString('en-US')}
              fill="#374151" fontSize={labelSize} />
          </Bar>
        </BarChart>
      </ChartContainer>}
  </ChartCard>;
}

export function GateCountCharts() {
  const state = useGateCounts();
  const id = useId();
  const data = state.data;
  const hasVisits = data && (
    data.daily.some((row) => row.visits > 0)
    || data.heatmap.some((cell) => cell.visits > 0)
    || data.monthlyByFiscalYear.some((year) => year.months.some((month) => month.visits > 0))
  );

  return <section aria-labelledby={id + '-title'} className="mt-10 border-t border-gray-200 pt-8">
    <header className="mb-5">
      <p className="text-xs uppercase tracking-widest text-folio-700 font-semibold mb-2">SenSource Vea</p>
      <h2 id={id + '-title'} className="text-2xl font-semibold text-gray-900">Gate counts</h2>
      <div className="mt-4 flex flex-wrap items-end gap-4">
        <div><label htmlFor={id + '-start'} className="block text-sm font-medium mb-1">Start date</label>
          <input id={id + '-start'} type="date" value={state.start} onChange={(event) => state.changeStart(event.target.value)}
            className="border border-gray-300 rounded-md bg-white px-3 py-2" /></div>
        <div><label htmlFor={id + '-end'} className="block text-sm font-medium mb-1">End date</label>
          <input id={id + '-end'} type="date" value={state.end} onChange={(event) => state.changeEnd(event.target.value)}
            className="border border-gray-300 rounded-md bg-white px-3 py-2" /></div>
        <div><label htmlFor={id + '-years'} className="block text-sm font-medium mb-1">Gate count years</label>
          <select id={id + '-years'} value={state.years} onChange={(event) => state.setYears(Number(event.target.value))}
            className="border border-gray-300 rounded-md bg-white px-3 py-2">
            {[1, 2, 3, 4, 5].map((year) => <option key={year} value={year}>{year}</option>)}
          </select></div>
      </div>
    </header>

    {state.query.isPending && <div role="status" aria-live="polite" className="text-sm text-gray-600">Loading gate counts…</div>}
    {state.query.isFetching && !state.query.isPending && <div role="status" aria-live="polite" className="text-sm text-gray-600">Updating gate counts…</div>}
    {state.query.isError && <div role="alert" className="my-4 rounded-md border border-red-200 bg-red-50 p-4 text-red-800">
      <p>Could not load gate counts.</p>
      <button type="button" onClick={() => void state.retry()} className="mt-2 font-medium underline">Retry gate counts</button>
    </div>}
    {data && <p className="mb-4 text-sm text-gray-600">
      Entries at {data.doors.join(', ')}, from SenSource Vea, Eastern time.
    </p>}
    {data && !hasVisits && <p className="rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
      No gate counts for this period.
    </p>}
    {data && hasVisits && <div className="grid grid-cols-1 xl:grid-cols-2 gap-6 min-w-0">
      <MonthlyVisitsChart data={data} />
      <Heatmap data={data.heatmap} period={data.period} />
      <DailyVisitsChart data={data} />
      <TopDaysChart data={data.topDays} />
    </div>}
  </section>;
}
