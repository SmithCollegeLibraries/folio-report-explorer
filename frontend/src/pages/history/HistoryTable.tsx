import { Database, Loader2, ChevronDown, ChevronUp } from 'lucide-react';
import HistoryRow from './HistoryRow';
import type { HistoryItem } from '../../types';

type SortKey = 'completedAt' | 'rowCount' | 'executionTimeMs';

interface Props {
  items: HistoryItem[];
  filteredItems: HistoryItem[];
  loading: boolean;
  isAdmin: boolean;
  // Sort
  sortKey: SortKey;
  sortDir: 'asc' | 'desc';
  onToggleSort: (key: SortKey) => void;
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
  copiedSqlId: string | null;
  selectedIds: Set<string>;
  // Per-row callbacks (item is provided by the table, jobId baked in by caller)
  onOpen: (item: HistoryItem) => void;
  onCancel: (jobId: string, e: React.MouseEvent) => void;
  onToggleError: (jobId: string, e: React.MouseEvent) => void;
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

function SortIcon({ col, sortKey, sortDir }: { col: SortKey; sortKey: SortKey; sortDir: 'asc' | 'desc' }) {
  if (sortKey !== col) return <ChevronDown size={12} className="opacity-30" />;
  return sortDir === 'asc' ? <ChevronUp size={12} /> : <ChevronDown size={12} />;
}

export default function HistoryTable({
  items, filteredItems, loading, isAdmin,
  sortKey, sortDir, onToggleSort,
  allSelectableChecked, hasSelectableItems, onToggleSelectAll,
  renamingId, renameValue, onRenameValueChange, renameSaving,
  cancellingId, deletingId, confirmDeleteId, expandedErrors, copiedSqlId, selectedIds,
  onOpen, onCancel, onToggleError, onCopySql,
  onStartRename, onCommitRename, onCancelRename,
  onConfirmDelete, onCancelDelete, onDelete, onSave, onToggleSelect,
}: Props) {
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
      <table className="w-full table-fixed text-sm border-separate border-spacing-0">
        <thead>
          <tr className="bg-gray-50 text-left">
            <th className="px-2 py-3 border-b border-gray-200 w-10 text-center">
              <input
                type="checkbox"
                checked={allSelectableChecked}
                onChange={onToggleSelectAll}
                disabled={!hasSelectableItems}
                title="Select all deletable rows"
                className="w-4 h-4 rounded text-folio-600 focus:ring-folio-400 disabled:opacity-40"
              />
            </th>
            <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 w-[34%]">
              Query
            </th>
            <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap">
              Status
            </th>
            <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap">
              Source
            </th>
            <th
              className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap cursor-pointer select-none hover:text-folio-700"
              onClick={() => onToggleSort('rowCount')}
            >
              <span className="flex items-center gap-1">
                Rows <SortIcon col="rowCount" sortKey={sortKey} sortDir={sortDir} />
              </span>
            </th>
            <th
              className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap cursor-pointer select-none hover:text-folio-700"
              onClick={() => onToggleSort('executionTimeMs')}
            >
              <span className="flex items-center gap-1">
                Time <SortIcon col="executionTimeMs" sortKey={sortKey} sortDir={sortDir} />
              </span>
            </th>
            {isAdmin && (
              <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap">
                Run by
              </th>
            )}
            <th
              className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap cursor-pointer select-none hover:text-folio-700"
              onClick={() => onToggleSort('completedAt')}
            >
              <span className="flex items-center gap-1">
                Date <SortIcon col="completedAt" sortKey={sortKey} sortDir={sortDir} />
              </span>
            </th>
            <th className="px-4 py-3 border-b border-gray-200 w-72" />
          </tr>
        </thead>
        <tbody>
          {filteredItems.map((item, i) => (
            <HistoryRow
              key={item.jobId}
              item={item}
              index={i}
              isAdmin={isAdmin}
              isRenaming={renamingId === item.jobId}
              renameValue={renameValue}
              onRenameValueChange={onRenameValueChange}
              renameSaving={renameSaving}
              onStartRename={(e) => onStartRename(item, e)}
              onCommitRename={() => onCommitRename(item.jobId, item.name)}
              onCancelRename={onCancelRename}
              isCancelling={cancellingId === item.jobId}
              isDeleting={deletingId === item.jobId}
              confirmingDelete={confirmDeleteId === item.jobId}
              errExpanded={expandedErrors.has(item.jobId)}
              sqlCopied={copiedSqlId === item.jobId}
              isSelected={selectedIds.has(item.jobId)}
              onOpen={() => onOpen(item)}
              onCancel={(e) => onCancel(item.jobId, e)}
              onToggleError={(e) => onToggleError(item.jobId, e)}
              onCopySql={(e) => onCopySql(item.jobId, item.sql, e)}
              onConfirmDelete={() => onConfirmDelete(item.jobId)}
              onCancelDelete={onCancelDelete}
              onDelete={() => onDelete(item.jobId)}
              onSave={(pinned, e) => onSave(item, pinned, e)}
              onToggleSelect={() => onToggleSelect(item.jobId)}
            />
          ))}
        </tbody>
      </table>
    </div>
  );
}
