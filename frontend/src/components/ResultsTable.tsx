import { useMemo, useState } from 'react';
import {
  useReactTable,
  getCoreRowModel,
  getSortedRowModel,
  flexRender,
  type ColumnDef,
  type SortingState,
} from '@tanstack/react-table';
import { ArrowUpDown, Download, BarChart3, Table2 } from 'lucide-react';
import type { ExecuteResponse } from '../types';
import { downloadCsv } from '../utils/csv';
import ChartPanel from './ChartPanel';

interface Props {
  data: ExecuteResponse;
  drillThrough?: {
    column: string;
    onClick: (value: string, row: Record<string, unknown>) => void;
  };
}

export default function ResultsTable({ data, drillThrough }: Props) {
  const [sorting, setSorting] = useState<SortingState>([]);
  const [view, setView] = useState<'table' | 'chart'>('table');

  const columns = useMemo<ColumnDef<Record<string, unknown>>[]>(
    () =>
      data.columns.map((col) => ({
        accessorKey: col,
        header: ({ column }) => (
          <button
            className="flex items-center gap-1 font-semibold text-xs"
            onClick={() => column.toggleSorting()}
          >
            {col}
            <ArrowUpDown size={12} />
          </button>
        ),
        cell: ({ getValue, row }) => {
          const val = getValue();
          if (val === null) return <span className="text-gray-400 italic">null</span>;
          if (typeof val === 'object') return JSON.stringify(val);
          const asText = String(val);

          if (drillThrough && col === drillThrough.column && asText.trim() !== '') {
            return (
              <button
                type="button"
                onClick={() => drillThrough.onClick(asText, row.original)}
                className="text-folio-700 underline decoration-dotted underline-offset-2 hover:text-folio-900"
                title={`Open related report for ${asText}`}
              >
                {asText}
              </button>
            );
          }

          // Cap floating-point numbers at 2 decimal places for readability
          if (typeof val === 'number' && !Number.isInteger(val)) {
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(val);
          }
          // Postgres numeric columns sometimes arrive as decimal strings with many dp
          if (typeof val === 'string' && /^-?\d+\.\d{3,}$/.test(val)) {
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(parseFloat(val));
          }
          return String(val);
        },
      })),
    [data.columns],
  );

  const table = useReactTable({
    data: data.rows,
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
  });

  const exportCsv = () => downloadCsv(data.columns, data.rows, 'query-results');
  const hasRows = data.rowCount > 0 && data.columns.length > 0;
  const downloadUrl = data.outputMode === 'file' ? data.downloadUrl : undefined;

  return (
    <div>
      {data.truncated && (
        <div
          role="alert"
          className="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
          This report reached its 100,000-row cap. Narrow the location to retrieve the remaining records.
        </div>
      )}
      {data.identifierSkippedCount && data.identifierSkippedCount > 0 && (
        <div
          role="alert"
          className="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
          {data.identifierSkippedCount} identifier{data.identifierSkippedCount === 1 ? '' : 's'} could not be exported because {data.identifierSkippedCount === 1 ? 'it was' : 'they were'} not a valid FOLIO UUID.
        </div>
      )}
      {/* Summary bar */}
      <div data-testid="results-summary-bar" className="flex items-center justify-between px-2 py-1 bg-gray-50 border rounded-t-lg text-xs text-gray-600">
        <div className="flex gap-3">
          <span>
            <strong>{data.rowCount}</strong> row{data.rowCount !== 1 ? 's' : ''}
          </span>
          <span>
            <strong>{data.executionTimeMs}</strong>ms
          </span>
          <span>
            <strong>{data.columns.length}</strong> column{data.columns.length !== 1 ? 's' : ''}
          </span>
        </div>
        <button
          onClick={exportCsv}
          disabled={!hasRows}
          className="flex items-center gap-1 text-folio-600 hover:text-folio-800"
        >
          <Download size={14} /> CSV
        </button>
        {downloadUrl && (
          <a
            href={downloadUrl}
            className="flex items-center gap-1 ml-2 text-green-700 hover:text-green-900"
          >
            <Download size={14} /> Download Full CSV
          </a>
        )}
        <button
          onClick={() => setView(view === 'table' ? 'chart' : 'table')}
          className={`flex items-center gap-1 ml-2 px-2 py-0.5 rounded border transition-colors ${
            view === 'chart'
              ? 'bg-folio-50 text-folio-700 border-folio-300'
              : 'text-gray-500 border-gray-200 hover:border-gray-300'
          }`}
          title={view === 'table' ? 'Show chart' : 'Show table'}
        >
          {view === 'table' ? <BarChart3 size={14} /> : <Table2 size={14} />}
          {view === 'table' ? 'Chart' : 'Table'}
        </button>
      </div>

      {/* Chart view */}
      {view === 'chart' && (
        <div className="border border-t-0 rounded-b-lg bg-white">
          <ChartPanel data={data} />
        </div>
      )}

      {/* Table view */}
      {view === 'table' && (
      data.columns.length === 0 ? (
        <div className="border border-t-0 rounded-b-lg p-4 text-sm text-gray-600 bg-white">
          No inline preview rows are available for this result.
          {downloadUrl ? ' Use Download Full CSV to retrieve the export file.' : ''}
        </div>
      ) : (
      <div className="overflow-x-auto border border-t-0 rounded-b-lg">
        <table data-testid="results-table" className="w-full text-xs">
          <thead>
            {table.getHeaderGroups().map((hg) => (
              <tr key={hg.id} className="bg-gray-100 border-b">
                {hg.headers.map((header) => (
                  <th
                    key={header.id}
                    className="px-2 py-1.5 text-left font-mono"
                  >
                    {header.isPlaceholder
                      ? null
                      : flexRender(header.column.columnDef.header, header.getContext())}
                  </th>
                ))}
              </tr>
            ))}
          </thead>
          <tbody>
            {table.getRowModel().rows.map((row) => (
              <tr key={row.id} className="border-b hover:bg-gray-50">
                {row.getVisibleCells().map((cell) => (
                  <td key={cell.id} className="px-2 py-1 font-mono text-xs max-w-xs truncate">
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      )
      )}
    </div>
  );
}
