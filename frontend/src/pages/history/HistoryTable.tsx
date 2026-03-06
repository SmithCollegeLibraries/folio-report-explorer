import { useMemo, useState } from 'react';
import {
  useReactTable,
  getCoreRowModel,
  getSortedRowModel,
  flexRender,
  type ColumnDef,
  type SortingState,
  type VisibilityState,
} from '@tanstack/react-table';
import {
  Database, Loader2, ChevronDown, ChevronUp, AlertCircle, XCircle,
  Code, Check, Copy, User, Pencil, Bookmark, LayoutDashboard, Eye,
  Trash2, SlidersHorizontal,
} from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import SourceBadge from '../../components/SourceBadge';
import { fmtDate, fmtTime } from '../../utils/format';
import type { HistoryItem } from '../../types';

interface Props {
  items: HistoryItem[];
  filteredItems: HistoryItem[];
  loading: boolean;
  // Selection header checkbox
  allSelectableChecked: boolean;
  hasSelectableItems: boolean;
  onToggleSelectAll: () => void;
  // Per-row: item-keyed state
  renamingId: string | null;
  renameValue: string;
  onRenameValueChange: (v: string) => void;
  renameSaving: boolean;
  cancellingId: string | null;
  deletingId: string | null;
  confirmDeleteId: string | null;
  expandedErrors: Set<string>;
  expandedSql: Set<string>;
  copiedSqlId: string | null;
  selectedIds: Set<string>;
  // Per-row callbacks (item is provided by the table, jobId baked in by caller)
  onOpen: (item: HistoryItem) => void;
  onCancel: (jobId: string, e: React.MouseEvent) => void;
  onToggleError: (jobId: string, e: React.MouseEvent) => void;
  onToggleSql: (jobId: string, e: React.MouseEvent) => void;
  onCopySql: (jobId: string, sql: string, e: React.MouseEvent) => void;
  onStartRename: (item: HistoryItem, e: React.MouseEvent) => void;
  onCommitRename: (jobId: string, originalName: string | null) => void;
  onCancelRename: () => void;
  onConfirmDelete: (jobId: string) => void;
  onCancelDelete: () => void;
  onDelete: (jobId: string) => void;
  onSave: (item: HistoryItem, pinned: boolean, e: React.MouseEvent) => void;
  onToggleSelect: (jobId: string) => void;
}

function SortIcon({ sorted }: { sorted: false | 'asc' | 'desc' }) {
  if (!sorted) return <ChevronDown size={12} className="opacity-30" />;
  return sorted === 'asc' ? <ChevronUp size={12} /> : <ChevronDown size={12} />;
}

export default function HistoryTable({
  items, filteredItems, loading,
  allSelectableChecked, hasSelectableItems, onToggleSelectAll,
  renamingId, renameValue, onRenameValueChange, renameSaving,
  cancellingId, deletingId, confirmDeleteId, expandedErrors, expandedSql, copiedSqlId, selectedIds,
  onOpen, onCancel, onToggleError, onToggleSql, onCopySql,
  onStartRename, onCommitRename, onCancelRename,
  onConfirmDelete, onCancelDelete, onDelete, onSave, onToggleSelect,
}: Props) {
  const [sorting, setSorting] = useState<SortingState>([{ id: 'date', desc: true }]);
  const [showColumnMenu, setShowColumnMenu] = useState(false);
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() => {
    if (typeof window === 'undefined') {
      return { source: true, results: true, runBy: true };
    }
    const width = window.innerWidth;
    return {
      source: width >= 768,
      results: width >= 1024,
      runBy: width >= 1280,
    };
  });

  const columns = useMemo<ColumnDef<HistoryItem>[]>(() => [
    {
      id: 'select',
      header: () => (
        <div className="text-center">
          <input
            type="checkbox"
            checked={allSelectableChecked}
            onChange={onToggleSelectAll}
            disabled={!hasSelectableItems}
            title="Select all deletable rows"
            className="w-4 h-4 rounded text-folio-600 focus:ring-folio-400 disabled:opacity-40"
          />
        </div>
      ),
      cell: ({ row }) => {
        const item = row.original;
        return (
          <div className="text-center" onClick={(e) => e.stopPropagation()}>
            {item.canDelete ? (
              <input
                type="checkbox"
                checked={selectedIds.has(item.jobId)}
                onChange={() => onToggleSelect(item.jobId)}
                className="w-4 h-4 rounded text-folio-600 focus:ring-folio-400"
                title="Select for batch delete"
              />
            ) : (
              <span className="text-gray-300">—</span>
            )}
          </div>
        );
      },
      enableSorting: false,
      size: 44,
    },
    {
      id: 'query',
      header: () => <span className="font-semibold text-gray-600">Query</span>,
      cell: ({ row }) => {
        const item = row.original;
        const isRenaming = renamingId === item.jobId;
        if (isRenaming) {
          return (
            <form
              onSubmit={(e) => { e.preventDefault(); onCommitRename(item.jobId, item.name); }}
              onClick={(e) => e.stopPropagation()}
            >
              <input
                autoFocus
                value={renameValue}
                onChange={(e) => onRenameValueChange(e.target.value)}
                onBlur={() => onCommitRename(item.jobId, item.name)}
                onKeyDown={(e) => { if (e.key === 'Escape') onCancelRename(); }}
                disabled={renameSaving}
                placeholder="Query name…"
                className="w-full px-2 py-1 text-sm border rounded border-folio-400 focus:ring-1 focus:ring-folio-300 outline-none font-medium"
              />
            </form>
          );
        }
        return (
          <div className="max-w-[260px] md:max-w-[340px] xl:max-w-[420px]">
            {item.name
              ? <span className="font-medium text-gray-800 line-clamp-1">{item.name}</span>
              : <span className="italic text-gray-400 text-xs">Unnamed query</span>}
            <p className="text-[11px] text-gray-400 mt-0.5 truncate">SQL available in actions</p>
          </div>
        );
      },
      enableSorting: false,
      size: 320,
      minSize: 220,
    },
    {
      accessorKey: 'status',
      header: () => <span className="font-semibold text-gray-600">Status</span>,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
      enableSorting: false,
      size: 110,
    },
    {
      accessorKey: 'source',
      header: () => <span className="font-semibold text-gray-600">Source</span>,
      cell: ({ row }) => <SourceBadge source={row.original.source} />,
      enableSorting: false,
      size: 100,
    },
    {
      id: 'results',
      accessorFn: (row) => row.rowCount,
      header: ({ column }) => (
        <button
          onClick={column.getToggleSortingHandler()}
          className="flex items-center gap-1 font-semibold text-gray-600 hover:text-folio-700"
        >
          Results <SortIcon sorted={column.getIsSorted()} />
        </button>
      ),
      cell: ({ row }) => {
        const item = row.original;
        if (item.status !== 'completed') return <span className="text-gray-400">—</span>;
        return (
          <span className="text-gray-600 tabular-nums whitespace-nowrap">
            {item.rowCount.toLocaleString()} rows · {item.executionTimeMs > 0 ? fmtTime(item.executionTimeMs) : '—'}
          </span>
        );
      },
      size: 170,
    },
    {
      accessorKey: 'runBy',
      header: () => <span className="font-semibold text-gray-600">Run by</span>,
      cell: ({ row }) => {
        const runBy = row.original.runBy;
        return runBy
          ? <span className="flex items-center gap-1 text-gray-500 text-xs whitespace-nowrap"><User size={11} />{runBy}</span>
          : <span className="text-gray-400">—</span>;
      },
      enableSorting: false,
      size: 140,
    },
    {
      id: 'date',
      accessorFn: (row) => row.completedAt ?? row.startedAt ?? row.createdAt,
      header: ({ column }) => (
        <button
          onClick={column.getToggleSortingHandler()}
          className="flex items-center gap-1 font-semibold text-gray-600 hover:text-folio-700"
        >
          Date <SortIcon sorted={column.getIsSorted()} />
        </button>
      ),
      cell: ({ row, table }) => {
        const item = row.original;
        const runByVisible = table.getColumn('runBy')?.getIsVisible();
        return (
          <div className="text-gray-500 text-xs">
            <div>
              {item.completedAt
                ? fmtDate(item.completedAt)
                : item.startedAt
                  ? <span className="text-blue-500">{fmtDate(item.startedAt)}</span>
                  : <span className="text-amber-500">{fmtDate(item.createdAt)}</span>}
            </div>
            {!runByVisible && (
              <div className="mt-0.5 text-[11px] text-gray-400 truncate max-w-[170px]">
                {item.runBy ? `Run by: ${item.runBy}` : 'Run by: —'}
              </div>
            )}
          </div>
        );
      },
      size: 190,
    },
    {
      id: 'actions',
      header: () => null,
      cell: ({ row }) => {
        const item = row.original;
        const isActive = item.status === 'pending' || item.status === 'pending_export' || item.status === 'running';
        const isFailed = item.status === 'failed';
        const isCompleted = item.status === 'completed';
        const isCancelling = cancellingId === item.jobId;
        const isDeleting = deletingId === item.jobId;
        const confirmingDelete = confirmDeleteId === item.jobId;
        return (
          <div className="flex items-center justify-end gap-1 w-[210px]" onClick={(e) => e.stopPropagation()}>
            {isActive && (
              <>
                <button
                  onClick={(e) => onToggleSql(item.jobId, e)}
                  title={expandedSql.has(item.jobId) ? 'Hide SQL' : 'View SQL'}
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-folio-200 text-folio-700 hover:bg-folio-50 transition-colors"
                >
                  <Code size={11} />
                </button>
                <button
                  onClick={(e) => onCancel(item.jobId, e)}
                  disabled={isCancelling}
                  title="Cancel query"
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-red-200 text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50"
                >
                  {isCancelling ? <Loader2 size={11} className="animate-spin" /> : <XCircle size={11} />}
                </button>
              </>
            )}

            {isFailed && (
              <button
                onClick={(e) => onToggleError(item.jobId, e)}
                title={expandedErrors.has(item.jobId) ? 'Hide SQL and error' : 'Show SQL and error details'}
                className="inline-flex h-7 w-7 items-center justify-center rounded border border-red-200 text-red-600 hover:bg-red-50 transition-colors"
              >
                <AlertCircle size={11} />
              </button>
            )}

            {isCompleted && (
              <div className="flex items-center gap-1">
                <button
                  onClick={(e) => onToggleSql(item.jobId, e)}
                  title={expandedSql.has(item.jobId) ? 'Hide SQL' : 'View SQL'}
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-folio-200 text-folio-700 hover:bg-folio-50 transition-colors"
                >
                  <Code size={11} />
                </button>
                <button
                  onClick={(e) => onStartRename(item, e)}
                  title="Rename"
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:text-folio-600 hover:bg-folio-50 transition-colors"
                >
                  <Pencil size={13} />
                </button>
                <button
                  onClick={(e) => onSave(item, false, e)}
                  title="Save to Library"
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:text-folio-600 hover:bg-folio-50 transition-colors"
                >
                  <Bookmark size={13} />
                </button>
                <button
                  onClick={(e) => onSave(item, true, e)}
                  title="Add to Dashboard"
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:text-folio-600 hover:bg-folio-50 transition-colors"
                >
                  <LayoutDashboard size={13} />
                </button>
                <button
                  onClick={() => onOpen(item)}
                  title="View results"
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:text-folio-600 hover:bg-folio-50 transition-colors"
                >
                  <Eye size={13} />
                </button>
              </div>
            )}

            {item.canDelete && (
              confirmingDelete ? (
                <div className="flex items-center gap-1 ml-1">
                  <span className="text-xs text-red-600 font-medium">Delete?</span>
                  <button
                    onClick={() => onDelete(item.jobId)}
                    className="px-1.5 py-0.5 text-xs rounded bg-red-600 text-white hover:bg-red-700 transition-colors"
                  >
                    Yes
                  </button>
                  <button
                    onClick={() => onCancelDelete()}
                    className="px-1.5 py-0.5 text-xs rounded border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
                  >
                    No
                  </button>
                </div>
              ) : (
                <button
                  onClick={(e) => { e.stopPropagation(); onConfirmDelete(item.jobId); }}
                  disabled={isDeleting}
                  title="Delete from history"
                  className="inline-flex h-7 w-7 items-center justify-center rounded border border-red-200 text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50"
                >
                  {isDeleting ? (
                    <Loader2 size={11} className="animate-spin" />
                  ) : (
                    <Trash2 size={11} />
                  )}
                </button>
              )
            )}
          </div>
        );
      },
      enableSorting: false,
      size: 200,
    },
  ], [
    allSelectableChecked,
    hasSelectableItems,
    onToggleSelectAll,
    selectedIds,
    onToggleSelect,
    renamingId,
    renameValue,
    onRenameValueChange,
    renameSaving,
    onCommitRename,
    onCancelRename,
    cancellingId,
    deletingId,
    confirmDeleteId,
    expandedSql,
    expandedErrors,
    onToggleSql,
    onCancel,
    onToggleError,
    onStartRename,
    onSave,
    onOpen,
    onDelete,
    onCancelDelete,
    onConfirmDelete,
  ]);

  const table = useReactTable({
    data: filteredItems,
    columns,
    state: { sorting, columnVisibility },
    onSortingChange: setSorting,
    onColumnVisibilityChange: setColumnVisibility,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
  });

  if (loading) {
    return (
      <div className="flex items-center justify-center py-16 gap-3 text-gray-400">
        <Loader2 size={20} className="animate-spin text-folio-600" />
        <span className="text-sm">Loading history…</span>
      </div>
    );
  }

  if (filteredItems.length === 0) {
    return (
      <div className="text-center py-16 text-gray-400">
        <Database size={40} className="mx-auto mb-3 opacity-40" />
        {items.length === 0 ? (
          <>
            <p className="font-medium">No queries yet</p>
            <p className="text-sm mt-1">Run a query from the Builder or Ask AI page.</p>
          </>
        ) : (
          <>
            <p className="font-medium">No matches</p>
            <p className="text-sm mt-1">Try a different search term or clear the filter.</p>
          </>
        )}
      </div>
    );
  }

  return (
    <div className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
      <div className="flex items-center justify-end px-3 py-2 border-b border-gray-200 bg-gray-50">
        <div className="relative">
          <button
            onClick={() => setShowColumnMenu((v) => !v)}
            className="inline-flex items-center gap-1 text-xs text-gray-600 border border-gray-300 rounded px-2 py-1 hover:bg-white transition-colors"
            title="Choose visible columns"
          >
            <SlidersHorizontal size={12} /> Columns
          </button>
          {showColumnMenu && (
            <div className="absolute right-0 mt-1 w-40 bg-white border border-gray-200 rounded-lg shadow-lg p-2 z-10">
              {['source', 'results', 'runBy'].map((columnId) => {
                const column = table.getColumn(columnId);
                if (!column) return null;
                return (
                  <label key={columnId} className="flex items-center gap-2 px-1 py-1 text-xs text-gray-700">
                    <input
                      type="checkbox"
                      checked={column.getIsVisible()}
                      onChange={column.getToggleVisibilityHandler()}
                      className="w-3.5 h-3.5 rounded text-folio-600 focus:ring-folio-400"
                    />
                    {columnId === 'runBy' ? 'Run by' : columnId[0].toUpperCase() + columnId.slice(1)}
                  </label>
                );
              })}
            </div>
          )}
        </div>
      </div>

      <div className="overflow-x-auto">
      <table className="w-full min-w-[760px] text-sm border-separate border-spacing-0">
        <thead>
          {table.getHeaderGroups().map((hg) => (
            <tr key={hg.id} className="bg-gray-50 text-left">
              {hg.headers.map((header) => (
                  <th
                  key={header.id}
                    className={`px-3 py-3.5 border-b border-gray-200 ${
                      header.id === 'select'
                        ? 'sticky left-0 z-20 bg-gray-50 shadow-[2px_0_0_0_rgba(229,231,235,0.8)]'
                        : header.id === 'actions'
                          ? 'sticky right-0 z-20 bg-gray-50 shadow-[-2px_0_0_0_rgba(229,231,235,0.8)]'
                          : ''
                    }`}
                    style={{ width: header.id === 'query' ? '40%' : header.getSize() }}
                >
                  {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                </th>
              ))}
            </tr>
          ))}
        </thead>
        <tbody>
          {table.getRowModel().rows.map((row, i) => {
            const item = row.original;
            const isCompleted = item.status === 'completed';
            const isRenaming = renamingId === item.jobId;
            const rowBg = i % 2 === 0 ? 'bg-white' : 'bg-gray-50/40';
            const showExpandedError = item.status === 'failed' && expandedErrors.has(item.jobId);
            const showExpandedSql = expandedSql.has(item.jobId);

            return (
              <>
                <tr
                  key={row.id}
                  className={`group transition-colors ${rowBg} ${isCompleted ? 'cursor-pointer hover:bg-folio-50' : 'cursor-default'}`}
                  onClick={() => !isRenaming && isCompleted && onOpen(item)}
                >
                  {row.getVisibleCells().map((cell) => (
                    <td
                      key={cell.id}
                      className={`px-3 py-3 border-b border-gray-100 align-top ${
                        cell.column.id === 'select'
                          ? `sticky left-0 z-10 ${rowBg} shadow-[2px_0_0_0_rgba(243,244,246,0.9)]`
                          : cell.column.id === 'actions'
                            ? `sticky right-0 z-10 ${rowBg} shadow-[-2px_0_0_0_rgba(243,244,246,0.9)]`
                            : ''
                      }`}
                    >
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  ))}
                </tr>

                {showExpandedError && (
                  <tr className={rowBg} key={`${row.id}-error`}>
                    <td colSpan={row.getVisibleCells().length} className="px-4 pb-4 border-b border-gray-100">
                      <div className="mt-1 space-y-3">
                        <div>
                          <div className="flex items-center justify-between mb-1">
                            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wide">SQL</span>
                            <button
                              onClick={(e) => onCopySql(item.jobId, item.sql, e)}
                              className="flex items-center gap-1 text-xs text-gray-400 hover:text-folio-600 px-2 py-0.5 rounded hover:bg-folio-50 transition-colors"
                            >
                              {copiedSqlId === item.jobId
                                ? <><Check size={11} className="text-green-500" /> Copied</>
                                : <><Copy size={11} /> Copy SQL</>}
                            </button>
                          </div>
                          <pre className="bg-gray-900 text-green-300 text-xs font-mono rounded p-3 whitespace-pre-wrap break-all max-h-48 overflow-y-auto leading-relaxed">
                            {item.sql}
                          </pre>
                        </div>
                        {item.errorMessage && (
                          <div>
                            <div className="text-xs font-semibold text-red-500 uppercase tracking-wide mb-1">
                              Error
                            </div>
                            <pre className="bg-red-50 border border-red-200 text-red-700 text-xs font-mono rounded p-3 whitespace-pre-wrap break-all max-h-40 overflow-y-auto leading-relaxed">
                              {item.errorMessage}
                            </pre>
                          </div>
                        )}
                      </div>
                    </td>
                  </tr>
                )}

                {showExpandedSql && (
                  <tr className={rowBg} key={`${row.id}-sql`}>
                    <td colSpan={row.getVisibleCells().length} className="px-4 pb-4 border-b border-gray-100">
                      <div className="mt-1">
                        <div className="flex items-center justify-between mb-1">
                          <span className="text-xs font-semibold text-gray-500 uppercase tracking-wide">SQL</span>
                          <button
                            onClick={(e) => onCopySql(item.jobId, item.sql, e)}
                            className="flex items-center gap-1 text-xs text-gray-400 hover:text-folio-600 px-2 py-0.5 rounded hover:bg-folio-50 transition-colors"
                          >
                            {copiedSqlId === item.jobId
                              ? <><Check size={11} className="text-green-500" /> Copied</>
                              : <><Copy size={11} /> Copy SQL</>}
                          </button>
                        </div>
                        <pre className="bg-gray-900 text-green-300 text-xs font-mono rounded p-3 whitespace-pre-wrap break-all max-h-56 overflow-y-auto leading-relaxed">
                          {item.sql}
                        </pre>
                      </div>
                    </td>
                  </tr>
                )}
              </>
            );
          })}
        </tbody>
      </table>
      </div>
    </div>
  );
}
