import { useState, useMemo, useEffect } from 'react';
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  PieChart,
  Pie,
  Cell,
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts';
import { BarChart3, TrendingUp, PieChart as PieIcon, AreaChart as AreaIcon } from 'lucide-react';
import type { ExecuteResponse } from '../types';

type ChartType = 'bar' | 'line' | 'pie' | 'area';

export type { ChartType };

interface Props {
  data: ExecuteResponse;
  /** When supplied from outside (e.g. dashboard card), hides the in-panel type selector */
  chartType?: ChartType;
  initialXAxis?: string;
  initialYAxes?: string[];
  /** Called whenever the user changes axis selection so the parent can persist it */
  onConfigChange?: (cfg: { xAxis: string; yAxes: string[] }) => void;
}

// FOLIO brand-inspired palette
const COLORS = [
  '#2b6cb0', // folio-600
  '#38a169', // green-600
  '#d69e2e', // yellow-600
  '#e53e3e', // red-500
  '#805ad5', // purple-600
  '#dd6b20', // orange-600
  '#319795', // teal-600
  '#d53f8c', // pink-600
  '#718096', // gray-500
  '#3182ce', // blue-500
];

const CHART_TYPES: { key: ChartType; label: string; icon: React.ReactNode }[] = [
  { key: 'bar', label: 'Bar', icon: <BarChart3 size={14} /> },
  { key: 'line', label: 'Line', icon: <TrendingUp size={14} /> },
  { key: 'pie', label: 'Pie', icon: <PieIcon size={14} /> },
  { key: 'area', label: 'Area', icon: <AreaIcon size={14} /> },
];

function isNumeric(value: unknown): boolean {
  if (value === null || value === undefined || value === '') return false;
  return !isNaN(Number(value));
}

export default function ChartPanel({ data, chartType: externalChartType, initialXAxis, initialYAxes, onConfigChange }: Props) {
  const [chartType, setChartType] = useState<ChartType>(externalChartType ?? 'bar');
  // Keep in sync if parent changes chartType prop
  useEffect(() => { if (externalChartType) setChartType(externalChartType); }, [externalChartType]);

  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const isControlled = externalChartType !== undefined;

  // Classify columns as numeric or categorical
  const { numericCols, categoricalCols } = useMemo(() => {
    const numeric: string[] = [];
    const categorical: string[] = [];

    for (const col of data.columns) {
      // Sample first 20 rows to determine if column is numeric
      const sample = data.rows.slice(0, 20);
      const numCount = sample.filter((r) => isNumeric(r[col])).length;
      if (numCount > sample.length * 0.6) {
        numeric.push(col);
      } else {
        categorical.push(col);
      }
    }

    return { numericCols: numeric, categoricalCols: categorical };
  }, [data.columns, data.rows]);

  // Default axis selections
  const [xAxis, setXAxis] = useState<string>(
    () => initialXAxis || categoricalCols[0] || data.columns[0] || '',
  );
  const [yAxes, setYAxes] = useState<string[]>(
    () => initialYAxes && initialYAxes.length > 0
      ? initialYAxes
      : numericCols.length > 0 ? [numericCols[0]] : data.columns.length > 1 ? [data.columns[1]] : [],
  );

  const notifyChange = (newX: string, newY: string[]) => {
    onConfigChange?.({ xAxis: newX, yAxes: newY });
  };

  const handleSetXAxis = (v: string) => { setXAxis(v); notifyChange(v, yAxes); };
  const handleToggleYAxis = (col: string) => {
    setYAxes((prev) => {
      const next = prev.includes(col)
        ? (prev.length > 1 ? prev.filter((c) => c !== col) : prev)
        : [...prev, col];
      notifyChange(xAxis, next);
      return next;
    });
  };
  // Prepare chart data (cast numeric values)
  const chartData = useMemo(() => {
    // For pie charts, limit rows
    const rows = chartType === 'pie' ? data.rows.slice(0, 20) : data.rows.slice(0, 500);
    return rows.map((row) => {
      const d: Record<string, unknown> = {};
      d[xAxis] = row[xAxis] ?? '(null)';
      for (const y of yAxes) {
        const val = row[y];
        d[y] = isNumeric(val) ? Number(val) : 0;
      }
      return d;
    });
  }, [data.rows, xAxis, yAxes, chartType]);

  if (data.columns.length < 2) {
    return (
      <div className="p-6 text-center text-gray-400 text-sm">
        Need at least 2 columns to create a chart.
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Controls */}
      <div className="flex flex-wrap items-center gap-4 px-4 pt-4">
        {/* Chart type — hidden when controlled from outside (e.g. dashboard card header) */}
        {!isControlled && (
          <div className="flex items-center gap-1">
            <span className="text-xs text-gray-500 mr-1">Type:</span>
            {CHART_TYPES.map((ct) => (
              <button
                key={ct.key}
                onClick={() => setChartType(ct.key)}
                className={`flex items-center gap-1 text-xs px-2 py-1 rounded border transition-colors ${
                  chartType === ct.key
                    ? 'bg-folio-600 text-white border-folio-600'
                    : 'border-gray-200 hover:border-gray-300 text-gray-600'
                }`}
              >
                {ct.icon}
                {ct.label}
              </button>
            ))}
          </div>
        )}

        {/* X axis */}
        <div className="flex items-center gap-1">
          <span className="text-xs text-gray-500">X:</span>
          <select
            value={xAxis}
            onChange={(e) => handleSetXAxis(e.target.value)}
            className="text-xs border rounded px-2 py-1"
          >
            {data.columns.map((col) => (
              <option key={col} value={col}>
                {col}
              </option>
            ))}
          </select>
        </div>

        {/* Y axes */}
        <div className="flex items-center gap-1 flex-wrap">
          <span className="text-xs text-gray-500">Y:</span>
          {data.columns
            .filter((c) => c !== xAxis)
            .map((col) => (
              <button
                key={col}
                onClick={() => handleToggleYAxis(col)}
                className={`text-xs px-2 py-0.5 rounded border transition-colors ${
                  yAxes.includes(col)
                    ? 'bg-folio-50 text-folio-700 border-folio-300 font-medium'
                    : 'text-gray-400 border-gray-200 hover:border-gray-300'
                }`}
              >
                {col}
              </button>
            ))}
        </div>
      </div>

      {/* Chart */}
      <div className="px-4 pb-4" style={{ height: 400 }}>
        <ResponsiveContainer width="100%" height="100%">
          {chartType === 'bar' ? (
            <BarChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
              <XAxis
                dataKey={xAxis}
                tick={{ fontSize: 11 }}
                angle={-35}
                textAnchor="end"
                height={60}
              />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip
                contentStyle={{ fontSize: 12, borderRadius: 8 }}
              />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              {yAxes.map((y, i) => (
                <Bar
                  key={y}
                  dataKey={y}
                  fill={COLORS[i % COLORS.length]}
                  radius={[4, 4, 0, 0]}
                />
              ))}
            </BarChart>
          ) : chartType === 'line' ? (
            <LineChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
              <XAxis
                dataKey={xAxis}
                tick={{ fontSize: 11 }}
                angle={-35}
                textAnchor="end"
                height={60}
              />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              {yAxes.map((y, i) => (
                <Line
                  key={y}
                  type="monotone"
                  dataKey={y}
                  stroke={COLORS[i % COLORS.length]}
                  strokeWidth={2}
                  dot={{ r: 3 }}
                  activeDot={{ r: 5 }}
                />
              ))}
            </LineChart>
          ) : chartType === 'area' ? (
            <AreaChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
              <XAxis
                dataKey={xAxis}
                tick={{ fontSize: 11 }}
                angle={-35}
                textAnchor="end"
                height={60}
              />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              {yAxes.map((y, i) => (
                <Area
                  key={y}
                  type="monotone"
                  dataKey={y}
                  stroke={COLORS[i % COLORS.length]}
                  fill={COLORS[i % COLORS.length]}
                  fillOpacity={0.2}
                  strokeWidth={2}
                />
              ))}
            </AreaChart>
          ) : (
            /* Pie chart — uses first Y axis */
            <PieChart>
              <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              <Pie
                data={chartData}
                dataKey={yAxes[0] || ''}
                nameKey={xAxis}
                cx="50%"
                cy="50%"
                outerRadius="80%"
                label={({ name, percent }) =>
                  `${name}: ${((percent ?? 0) * 100).toFixed(0)}%`
                }
                labelLine
              >
                {chartData.map((_, i) => (
                  <Cell key={i} fill={COLORS[i % COLORS.length]} />
                ))}
              </Pie>
            </PieChart>
          )}
        </ResponsiveContainer>
      </div>

      {/* Row count info */}
      <div className="px-4 pb-2 text-xs text-gray-400">
        {chartType === 'pie'
          ? `Showing top ${Math.min(20, data.rows.length)} of ${data.rowCount} rows`
          : `Showing ${Math.min(500, data.rows.length)} of ${data.rowCount} rows`}
      </div>
    </div>
  );
}
