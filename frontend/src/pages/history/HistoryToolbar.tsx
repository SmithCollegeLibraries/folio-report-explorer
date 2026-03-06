import { useRef } from 'react';
import { Search, X, Loader2, User } from 'lucide-react';

interface Props {
  search: string;
  onSearchChange: (v: string) => void;
  sourceFilter: string;
  onSourceFilterChange: (v: string) => void;
  mineOnly: boolean;
  onMineOnlyChange: (v: boolean) => void;
  filteredCount: number;
  // Batch delete
  selectedCount: number;
  batchDeleting: boolean;
  confirmBatchDelete: boolean;
  onConfirmBatchDelete: () => void;
  onCancelBatchDelete: () => void;
  onDeleteSelected: () => void;
}

export default function HistoryToolbar({
  search, onSearchChange,
  sourceFilter, onSourceFilterChange,
  mineOnly, onMineOnlyChange,
  filteredCount,
  selectedCount, batchDeleting, confirmBatchDelete,
  onConfirmBatchDelete, onCancelBatchDelete, onDeleteSelected,
}: Props) {
  const searchRef = useRef<HTMLInputElement>(null);

  return (
    <div className="flex flex-wrap items-center gap-3 mb-4">
      {/* Search */}
      <div className="relative flex-1 min-w-48">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input
          ref={searchRef}
          type="text"
          placeholder="Search by name, SQL, or user…"
          value={search}
          onChange={(e) => onSearchChange(e.target.value)}
          className="w-full pl-8 pr-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
        />
        {search && (
          <button
            onClick={() => onSearchChange('')}
            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
          >
            <X size={13} />
          </button>
        )}
      </div>

      {/* Source filter */}
      <select
        value={sourceFilter}
        onChange={(e) => onSourceFilterChange(e.target.value)}
        className="text-sm border rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-folio-300 outline-none"
      >
        <option value="">All sources</option>
        <option value="nl">Ask AI</option>
        <option value="builder">Builder</option>
        <option value="manual">Manual</option>
        <option value="report">Report</option>
      </select>

      <button
        type="button"
        onClick={() => onMineOnlyChange(!mineOnly)}
        className={`inline-flex items-center gap-1.5 text-sm border rounded-lg px-3 py-2 transition-colors ${
          mineOnly
            ? 'border-folio-300 bg-folio-50 text-folio-700'
            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
        }`}
        title="Show only queries I ran"
      >
        <User size={13} />
        My Queries
      </button>

      {/* Match count */}
      {(search || sourceFilter) && (
        <span className="text-xs text-gray-500">
          {filteredCount} match{filteredCount !== 1 ? 'es' : ''}
        </span>
      )}

      {/* Batch delete */}
      {selectedCount > 0 && (
        confirmBatchDelete ? (
          <div className="flex items-center gap-1">
            <span className="text-xs text-red-600 font-medium">
              Delete {selectedCount}?
            </span>
            <button
              onClick={onDeleteSelected}
              disabled={batchDeleting}
              className="text-xs px-2 py-1 rounded bg-red-600 text-white hover:bg-red-700 transition-colors disabled:opacity-50"
            >
              {batchDeleting ? <Loader2 size={11} className="animate-spin inline" /> : 'Yes'}
            </button>
            <button
              onClick={onCancelBatchDelete}
              disabled={batchDeleting}
              className="text-xs px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors disabled:opacity-50"
            >
              No
            </button>
          </div>
        ) : (
          <button
            onClick={onConfirmBatchDelete}
            disabled={batchDeleting}
            className="text-xs px-3 py-2 rounded border border-red-200 text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50"
          >
            Delete selected ({selectedCount})
          </button>
        )
      )}
    </div>
  );
}
