import {
  Loader2, XCircle, AlertCircle, Eye, Pencil, Bookmark,
  LayoutDashboard, Trash2, Copy, Check, User, Code,
} from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import SourceBadge from '../../components/SourceBadge';
import { fmtDate, fmtTime } from '../../utils/format';
import type { HistoryItem } from '../../types';

export interface HistoryRowProps {
  item: HistoryItem;
  /** Zero-based row index used for alternating background. */
  index: number;

  // ── Rename state (item-scoped booleans come from useInlineRename) ──
  isRenaming: boolean;
  renameValue: string;
  onRenameValueChange: (v: string) => void;
  renameSaving: boolean;
  onStartRename: (e: React.MouseEvent) => void;
  onCommitRename: () => void;
  onCancelRename: () => void;

  // ── Per-row action state ──
  isCancelling: boolean;
  isDeleting: boolean;
  confirmingDelete: boolean;
  errExpanded: boolean;
  sqlExpanded: boolean;
  sqlCopied: boolean;
  isSelected: boolean;

  // ── Callbacks ──
  onOpen: () => void;
  onCancel: (e: React.MouseEvent) => void;
  onToggleError: (e: React.MouseEvent) => void;
  onToggleSql: (e: React.MouseEvent) => void;
  onCopySql: (e: React.MouseEvent) => void;
  onConfirmDelete: () => void;
  onCancelDelete: () => void;
  onDelete: () => void;
  onSave: (pinned: boolean, e: React.MouseEvent) => void;
  onToggleSelect: () => void;
}

export default function HistoryRow({
  item, index,
  isRenaming, renameValue, onRenameValueChange, renameSaving,
  onStartRename, onCommitRename, onCancelRename,
  isCancelling, isDeleting, confirmingDelete, errExpanded, sqlExpanded, sqlCopied, isSelected,
  onOpen, onCancel, onToggleError, onToggleSql, onCopySql,
  onConfirmDelete, onCancelDelete, onDelete, onSave, onToggleSelect,
}: HistoryRowProps) {
  const isActive = item.status === 'pending' || item.status === 'running';
  const isFailed = item.status === 'failed';
  const isCompleted = item.status === 'completed';
  const rowBg = index % 2 === 0 ? 'bg-white' : 'bg-gray-50/40';
  const colSpan = 9;

  return (
    <>
      <tr
        className={`group transition-colors ${rowBg} ${
          isCompleted ? 'cursor-pointer hover:bg-folio-50' : 'cursor-default'
        }`}
        onClick={() => !isRenaming && isCompleted && onOpen()}
      >
        {/* Selection checkbox */}
        <td
          className="px-2 py-3 border-b border-gray-100 text-center"
          onClick={(e) => e.stopPropagation()}
        >
          {item.canDelete ? (
            <input
              type="checkbox"
              checked={isSelected}
              onChange={onToggleSelect}
              className="w-4 h-4 rounded text-folio-600 focus:ring-folio-400"
              title="Select for batch delete"
            />
          ) : (
            <span className="text-gray-300">—</span>
          )}
        </td>

        {/* Query name + SQL snippet */}
        <td className="px-4 py-3 border-b border-gray-100 w-[34%] max-w-0">
          {isRenaming ? (
            <form
              onSubmit={(e) => { e.preventDefault(); onCommitRename(); }}
              onClick={(e) => e.stopPropagation()}
            >
              <input
                autoFocus
                value={renameValue}
                onChange={(e) => onRenameValueChange(e.target.value)}
                onBlur={onCommitRename}
                onKeyDown={(e) => { if (e.key === 'Escape') onCancelRename(); }}
                disabled={renameSaving}
                placeholder="Query name…"
                className="w-full px-2 py-1 text-sm border rounded border-folio-400 focus:ring-1 focus:ring-folio-300 outline-none font-medium"
              />
            </form>
          ) : (
            <>
              {item.name
                ? <span className="font-medium text-gray-800 line-clamp-1">{item.name}</span>
                : <span className="italic text-gray-400 text-xs">Unnamed query</span>}
              <p className="text-xs text-gray-400 font-mono mt-0.5 block max-w-full truncate">
                {item.sql}
              </p>
            </>
          )}
        </td>

        {/* Status */}
        <td className="px-4 py-3 border-b border-gray-100 whitespace-nowrap">
          <StatusBadge status={item.status} />
        </td>

        {/* Source */}
        <td className="px-4 py-3 border-b border-gray-100">
          <SourceBadge source={item.source} />
        </td>

        {/* Row count */}
        <td className="px-4 py-3 border-b border-gray-100 text-gray-600 tabular-nums whitespace-nowrap">
          {isCompleted ? item.rowCount.toLocaleString() : '—'}
        </td>

        {/* Execution time */}
        <td className="px-4 py-3 border-b border-gray-100 text-gray-600 tabular-nums whitespace-nowrap">
          {item.executionTimeMs > 0 ? fmtTime(item.executionTimeMs) : '—'}
        </td>

        {/* Run by */}
        <td className="px-4 py-3 border-b border-gray-100 text-gray-500 text-xs whitespace-nowrap">
          {item.runBy
            ? <span className="flex items-center gap-1"><User size={11} />{item.runBy}</span>
            : '—'}
        </td>

        {/* Date */}
        <td className="px-4 py-3 border-b border-gray-100 text-gray-500 text-xs whitespace-nowrap">
          {item.completedAt
            ? fmtDate(item.completedAt)
            : item.startedAt
              ? <span className="text-blue-500">{fmtDate(item.startedAt)}</span>
              : <span className="text-amber-500">{fmtDate(item.createdAt)}</span>}
        </td>

        {/* Actions */}
        <td
          className="px-3 py-3 border-b border-gray-100"
          onClick={(e) => e.stopPropagation()}
        >
          <div className="flex items-center justify-end gap-0.5 min-w-[240px]">
            {isActive && (
              <>
                <button
                  onClick={(e) => onToggleSql(e)}
                  title={sqlExpanded ? 'Hide SQL' : 'View SQL'}
                  className="flex items-center gap-1 px-2 py-1 text-xs rounded text-folio-700 hover:bg-folio-50 border border-folio-200 transition-colors"
                >
                  <Code size={11} />
                  {sqlExpanded ? 'Hide SQL' : 'View SQL'}
                </button>
                <button
                  onClick={(e) => onCancel(e)}
                  disabled={isCancelling}
                  title="Cancel query"
                  className="flex items-center gap-1 px-2 py-1 text-xs rounded text-red-600 hover:bg-red-50 border border-red-200 transition-colors disabled:opacity-50"
                >
                  {isCancelling
                    ? <Loader2 size={11} className="animate-spin" />
                    : <XCircle size={11} />}
                  Cancel
                </button>
              </>
            )}

            {isFailed && (
              <button
                onClick={(e) => onToggleError(e)}
                title={errExpanded ? 'Hide SQL and error' : 'Show SQL and error details'}
                className="flex items-center gap-1 px-2 py-1 text-xs rounded text-red-600 hover:bg-red-50 border border-red-200 transition-colors"
              >
                <AlertCircle size={11} />
                {errExpanded ? 'Hide details' : 'Details'}
              </button>
            )}

            {isCompleted && (
              <div className="hidden group-hover:flex items-center gap-0.5">
                <button
                  onClick={(e) => onStartRename(e)}
                  title="Rename"
                  className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors"
                >
                  <Pencil size={13} />
                </button>
                <button
                  onClick={(e) => onSave(false, e)}
                  title="Save to Library"
                  className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors"
                >
                  <Bookmark size={13} />
                </button>
                <button
                  onClick={(e) => onSave(true, e)}
                  title="Add to Dashboard"
                  className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors"
                >
                  <LayoutDashboard size={13} />
                </button>
                <button
                  onClick={onOpen}
                  title="View results"
                  className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors"
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
                    onClick={onDelete}
                    className="px-1.5 py-0.5 text-xs rounded bg-red-600 text-white hover:bg-red-700 transition-colors"
                  >
                    Yes
                  </button>
                  <button
                    onClick={onCancelDelete}
                    className="px-1.5 py-0.5 text-xs rounded border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
                  >
                    No
                  </button>
                </div>
              ) : (
                <button
                  onClick={(e) => { e.stopPropagation(); onConfirmDelete(); }}
                  disabled={isDeleting}
                  title="Delete from history"
                  className="px-2 py-1 text-xs rounded border border-red-200 text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50 ml-1"
                >
                  {isDeleting ? (
                    <span className="flex items-center gap-1">
                      <Loader2 size={11} className="animate-spin" /> Deleting…
                    </span>
                  ) : (
                    <span className="flex items-center gap-1">
                      <Trash2 size={11} /> Delete
                    </span>
                  )}
                </button>
              )
            )}
          </div>
        </td>
      </tr>

      {/* Inline error expansion — SQL + error message */}
      {isFailed && errExpanded && (
        <tr className={rowBg}>
          <td colSpan={colSpan} className="px-4 pb-4 border-b border-gray-100">
            <div className="mt-1 space-y-3">
              <div>
                <div className="flex items-center justify-between mb-1">
                  <span className="text-xs font-semibold text-gray-500 uppercase tracking-wide">SQL</span>
                  <button
                    onClick={(e) => onCopySql(e)}
                    className="flex items-center gap-1 text-xs text-gray-400 hover:text-folio-600 px-2 py-0.5 rounded hover:bg-folio-50 transition-colors"
                  >
                    {sqlCopied
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

      {/* Inline active SQL expansion */}
      {isActive && sqlExpanded && (
        <tr className={rowBg}>
          <td colSpan={colSpan} className="px-4 pb-4 border-b border-gray-100">
            <div className="mt-1">
              <div className="flex items-center justify-between mb-1">
                <span className="text-xs font-semibold text-gray-500 uppercase tracking-wide">SQL</span>
                <button
                  onClick={(e) => onCopySql(e)}
                  className="flex items-center gap-1 text-xs text-gray-400 hover:text-folio-600 px-2 py-0.5 rounded hover:bg-folio-50 transition-colors"
                >
                  {sqlCopied
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
}
