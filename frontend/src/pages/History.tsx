import { useState, useEffect, useCallback, useMemo } from 'react';
import {
  History as HistoryIcon, ChevronLeft, ChevronRight, Activity,
  CheckCircle2, AlertCircle,
} from 'lucide-react';
import { checkJobStatus, cancelJob, deleteHistoryJob } from '../api/client';
import { useAuth } from '../hooks/useAuth';
import { useHistoryData } from '../hooks/useHistoryData';
import { useSelectionManager } from '../hooks/useSelectionManager';
import { useInlineRename } from '../hooks/useInlineRename';
import { useCopyToClipboard } from '../hooks/useCopyToClipboard';
import HistoryToolbar from './history/HistoryToolbar';
import HistoryTable from './history/HistoryTable';
import HistoryResultsModal from './history/HistoryResultsModal';
import SaveQueryDialog from '../components/SaveQueryDialog';
import type { HistoryItem, JobStatusResponse } from '../types';

type SortKey = 'completedAt' | 'rowCount' | 'executionTimeMs';

const STATUS_TABS: { value: string; label: string; icon: React.ElementType }[] = [
  { value: 'all',       label: 'All',       icon: HistoryIcon },
  { value: 'active',    label: 'Active',     icon: Activity },
  { value: 'completed', label: 'Completed',  icon: CheckCircle2 },
  { value: 'failed',    label: 'Failed',     icon: AlertCircle },
];

export default function History() {
  const { isAdmin } = useAuth();

  // ── Server data, pagination, tab, auto-refresh ───────────────────
  const {
    items, setItems, total, setTotal, offset, setOffset, loading, error, setError,
    statusTab, handleTabChange, hasActive, load, limit, totalPages, currentPage,
    expandedErrors, toggleExpandError,
  } = useHistoryData();

  // ── Client-side filtering & sorting ─────────────────────────────
  const [search, setSearch] = useState('');
  const [sourceFilter, setSourceFilter] = useState('');
  const [sortKey, setSortKey] = useState<SortKey>('completedAt');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

  const filteredItems = useMemo(() => {
    return items
      .filter((item) => {
        const q = search.toLowerCase();
        if (q && !(
          (item.name ?? '').toLowerCase().includes(q) ||
          item.sql.toLowerCase().includes(q) ||
          (item.runBy ?? '').toLowerCase().includes(q)
        )) return false;
        if (sourceFilter && item.source !== sourceFilter) return false;
        return true;
      })
      .sort((a, b) => {
        const getVal = (i: HistoryItem) =>
          sortKey === 'completedAt' ? (i.completedAt ?? i.createdAt) : i[sortKey] as string | number;
        const av = getVal(a);
        const bv = getVal(b);
        const al = typeof av === 'string' ? av.toLowerCase() : (av ?? 0);
        const bl = typeof bv === 'string' ? bv.toLowerCase() : (bv ?? 0);
        if (al < bl) return sortDir === 'asc' ? -1 : 1;
        if (al > bl) return sortDir === 'asc' ? 1 : -1;
        return 0;
      });
  }, [items, search, sourceFilter, sortKey, sortDir]);

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    else { setSortKey(key); setSortDir('desc'); }
  };

  // ── Selection ────────────────────────────────────────────────────
  const selectableIds = useMemo(
    () => filteredItems.filter((i) => i.canDelete).map((i) => i.jobId),
    [filteredItems],
  );
  const selection = useSelectionManager(selectableIds);

  // ── Batch delete ─────────────────────────────────────────────────
  const [batchDeleting, setBatchDeleting] = useState(false);
  const [confirmBatchDelete, setConfirmBatchDelete] = useState(false);

  useEffect(() => {
    if (selection.selectedCount === 0) setConfirmBatchDelete(false);
  }, [selection.selectedCount]);

  const handleDeleteSelected = async () => {
    const ids = selectableIds.filter((id) => selection.selectedIds.has(id));
    if (!ids.length) return;
    setBatchDeleting(true);
    try {
      const results = await Promise.allSettled(ids.map((id) => deleteHistoryJob(id)));
      const deletedIds = ids.filter((_, idx) => results[idx].status === 'fulfilled');
      const failedCount = ids.length - deletedIds.length;
      if (deletedIds.length) {
        const deletedSet = new Set(deletedIds);
        setItems((prev) => prev.filter((i) => !deletedSet.has(i.jobId)));
        setTotal((prev) => prev - deletedIds.length);
        selection.removeIds(deletedIds);
      }
      if (failedCount > 0) {
        setError(`Deleted ${deletedIds.length}, failed to delete ${failedCount}.`);
      }
    } finally {
      setBatchDeleting(false);
      setConfirmBatchDelete(false);
    }
  };

  // ── Inline rename ────────────────────────────────────────────────
  const rename = useInlineRename({
    onCommit: (jobId, newName) => {
      setItems((prev) => prev.map((i) => i.jobId === jobId ? { ...i, name: newName || null } : i));
      setModalItem((m) => m?.jobId === jobId ? { ...m, name: newName || null } : m);
    },
    onError: (msg) => setError(msg),
  });

  // ── Copy SQL ─────────────────────────────────────────────────────
  const { copiedId: copiedSqlId, copy: copySql } = useCopyToClipboard();
  const handleCopySql = (jobId: string, sql: string, e: React.MouseEvent) => {
    e.stopPropagation();
    copySql(jobId, sql);
  };

  // ── Single row delete ────────────────────────────────────────────
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [confirmDeleteId, setConfirmDeleteId] = useState<string | null>(null);

  const handleDelete = async (jobId: string) => {
    setConfirmDeleteId(null);
    setDeletingId(jobId);
    try {
      await deleteHistoryJob(jobId);
      setItems((prev) => prev.filter((i) => i.jobId !== jobId));
      setTotal((prev) => prev - 1);
      selection.removeIds([jobId]);
    } catch (err: any) {
      setError(err.response?.data?.error || 'Delete failed');
    } finally {
      setDeletingId(null);
    }
  };

  // ── Cancel active job ────────────────────────────────────────────
  const [cancellingId, setCancellingId] = useState<string | null>(null);

  const handleCancel = async (jobId: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setCancellingId(jobId);
    try {
      await cancelJob(jobId);
      await load();
    } catch (err: any) {
      setError(err.response?.data?.error || 'Cancel failed');
    } finally {
      setCancellingId(null);
    }
  };

  // ── Results modal ────────────────────────────────────────────────
  const [modalItem, setModalItem] = useState<HistoryItem | null>(null);
  const [modalJob, setModalJob] = useState<JobStatusResponse | null>(null);
  const [modalLoading, setModalLoading] = useState(false);

  const openModal = async (item: HistoryItem) => {
    if (item.status !== 'completed') return;
    setModalItem(item);
    setModalJob(null);
    setModalLoading(true);
    try {
      const data = await checkJobStatus(item.jobId);
      setModalJob(data);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to load results');
    } finally {
      setModalLoading(false);
    }
  };

  const closeModal = useCallback(() => { setModalItem(null); setModalJob(null); }, []);

  const handleModalRename = (newName: string) => {
    if (!modalItem) return;
    setItems((prev) => prev.map((i) => i.jobId === modalItem.jobId ? { ...i, name: newName || null } : i));
    setModalItem((m) => m ? { ...m, name: newName || null } : m);
  };

  // ── Save / pin dialog ────────────────────────────────────────────
  const [savingItem, setSavingItem] = useState<HistoryItem | null>(null);
  const [saveInitialPin, setSaveInitialPin] = useState(false);

  const openSaveDialog = (item: HistoryItem, pinned: boolean, e?: React.MouseEvent) => {
    e?.stopPropagation();
    setSavingItem(item);
    setSaveInitialPin(pinned);
    closeModal();
  };

  // ─────────────────────────────────────────────────────────────────
  return (
    <div className="max-w-screen-xl mx-auto p-6">
      {/* Page header */}
      <div className="flex items-center gap-3 mb-6">
        <HistoryIcon className="text-folio-600 flex-shrink-0" size={22} />
        <h1 className="text-2xl font-bold text-gray-800">Query History</h1>
        <span className="text-sm text-gray-400 ml-1">
          {total} {total === 1 ? 'query' : 'queries'}
        </span>
        {hasActive && (
          <span className="ml-2 flex items-center gap-1 text-xs text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full font-medium animate-pulse">
            <Activity size={11} /> Live
          </span>
        )}
      </div>

      {/* Status tabs */}
      <div className="flex gap-1 mb-4 border-b border-gray-200">
        {STATUS_TABS.map(({ value, label, icon: Icon }) => (
          <button
            key={value}
            onClick={() => handleTabChange(value)}
            className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              statusTab === value
                ? 'border-folio-600 text-folio-700'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            <Icon size={13} />
            {label}
          </button>
        ))}
      </div>

      {/* Error banner */}
      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-3 mb-4 text-red-700 text-sm">
          {error}
        </div>
      )}

      {/* Filters + batch actions */}
      <HistoryToolbar
        search={search}
        onSearchChange={setSearch}
        sourceFilter={sourceFilter}
        onSourceFilterChange={setSourceFilter}
        filteredCount={filteredItems.length}
        selectedCount={selection.selectedCount}
        batchDeleting={batchDeleting}
        confirmBatchDelete={confirmBatchDelete}
        onConfirmBatchDelete={() => setConfirmBatchDelete(true)}
        onCancelBatchDelete={() => setConfirmBatchDelete(false)}
        onDeleteSelected={handleDeleteSelected}
      />

      {/* Table */}
      <HistoryTable
        items={items}
        filteredItems={filteredItems}
        loading={loading}
        isAdmin={isAdmin}
        sortKey={sortKey}
        sortDir={sortDir}
        onToggleSort={toggleSort}
        allSelectableChecked={selection.allChecked}
        hasSelectableItems={selectableIds.length > 0}
        onToggleSelectAll={selection.toggleAll}
        renamingId={rename.renamingId}
        renameValue={rename.renameValue}
        onRenameValueChange={(v) => rename.setRenameValue(() => v)}
        renameSaving={rename.renameSaving}
        cancellingId={cancellingId}
        deletingId={deletingId}
        confirmDeleteId={confirmDeleteId}
        expandedErrors={expandedErrors}
        copiedSqlId={copiedSqlId}
        selectedIds={selection.selectedIds}
        onOpen={openModal}
        onCancel={handleCancel}
        onToggleError={(jobId, e) => { e.stopPropagation(); toggleExpandError(jobId); }}
        onCopySql={handleCopySql}
        onStartRename={(item, e) => rename.start(item.jobId, item.name, e)}
        onCommitRename={(jobId, originalName) => rename.commit(jobId, originalName)}
        onCancelRename={rename.cancel}
        onConfirmDelete={(jobId) => setConfirmDeleteId(jobId)}
        onCancelDelete={() => setConfirmDeleteId(null)}
        onDelete={handleDelete}
        onSave={(item, pinned, e) => openSaveDialog(item, pinned, e)}
        onToggleSelect={selection.toggleOne}
      />

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="flex items-center justify-between mt-4">
          <button
            onClick={() => setOffset(Math.max(0, offset - limit))}
            disabled={offset === 0}
            className="flex items-center gap-1 px-3 py-1.5 text-sm border rounded-lg disabled:opacity-40 hover:bg-gray-50"
          >
            <ChevronLeft size={14} /> Previous
          </button>
          <span className="text-sm text-gray-500">
            Page {currentPage} of {totalPages}
          </span>
          <button
            onClick={() => setOffset(offset + limit)}
            disabled={offset + limit >= total}
            className="flex items-center gap-1 px-3 py-1.5 text-sm border rounded-lg disabled:opacity-40 hover:bg-gray-50"
          >
            Next <ChevronRight size={14} />
          </button>
        </div>
      )}

      {/* Results modal */}
      {modalItem && (
        <HistoryResultsModal
          item={modalItem}
          job={modalJob}
          loading={modalLoading}
          onClose={closeModal}
          onRename={handleModalRename}
          onSave={(pinned) => openSaveDialog(modalItem, pinned)}
        />
      )}

      {/* Save-to-library / pin-to-dashboard dialog */}
      {savingItem && (
        <SaveQueryDialog
          item={savingItem}
          initialPinned={saveInitialPin}
          onClose={() => setSavingItem(null)}
          onSaved={() => setSavingItem(null)}
        />
      )}
    </div>
  );
}
