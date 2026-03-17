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
}

export default function ResultsTable({ data }: Props) {
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
        cell: ({ getValue }) => {
          const val = getValue();
          if (val === null) return <span className="text-gray-400 italic">null</span>;
          if (typeof val === 'object') return JSON.stringify(val);
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

  return (
    <div>
      {/* Summary bar */}
      <div className="flex items-center justify-between px-3 py-2 bg-gray-50 border rounded-t-lg text-xs text-gray-600">
        <div className="flex gap-4">
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
          className="flex items-center gap-1 text-folio-600 hover:text-folio-800"
        >
          <Download size={14} /> CSV
        </button>
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
      <div className="overflow-x-auto border border-t-0 rounded-b-lg">
        <table className="w-full text-sm">
          <thead>
            {table.getHeaderGroups().map((hg) => (
              <tr key={hg.id} className="bg-gray-100 border-b">
                {hg.headers.map((header) => (
                  <th
                    key={header.id}
                    className="px-3 py-2 text-left font-mono"
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
                  <td key={cell.id} className="px-3 py-1.5 font-mono text-xs max-w-xs truncate">
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      )}
    </div>
  );
}
