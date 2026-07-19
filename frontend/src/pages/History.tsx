import { useState, useEffect, useCallback, useLayoutEffect, useMemo, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  History as HistoryIcon, ChevronLeft, ChevronRight, Activity,
  CheckCircle2, AlertCircle, Lightbulb,
} from 'lucide-react';
import {
  checkJobStatus,
  cancelJob,
  deleteHistoryJob,
  fetchIndexRecommendations,
  fetchHistorySuggestions,
} from '../api/client';
import { useHistoryData } from '../hooks/useHistoryData';
import type { HistoryViewParameters } from '../hooks/useHistoryData';
import { useSelectionManager } from '../hooks/useSelectionManager';
import { useInlineRename } from '../hooks/useInlineRename';
import { useCopyToClipboard } from '../hooks/useCopyToClipboard';
import HistoryToolbar from './history/HistoryToolbar';
import HistoryTable from './history/HistoryTable';
import HistoryResultsModal from './history/HistoryResultsModal';
import {
  deriveHistoryDeletionState,
  isDeletableHistoryItem,
} from './history/historyDeletionState';
import SaveQueryDialog from '../components/SaveQueryDialog';
import type {
  HistoryItem,
  JobStatusResponse,
  IndexRecommendationResponse,
  HistorySuggestionsResponse,
} from '../types';

const STATUS_TABS: { value: string; label: string; icon: React.ElementType }[] = [
  { value: 'all',       label: 'All',       icon: HistoryIcon },
  { value: 'active',    label: 'Active',     icon: Activity },
  { value: 'completed', label: 'Completed',  icon: CheckCircle2 },
  { value: 'failed',    label: 'Failed',     icon: AlertCircle },
];

export default function History() {
  const navigate = useNavigate();
  const { jobId: jobIdParam } = useParams<{ jobId: string }>();

  // ── Server data, pagination, tab, auto-refresh ───────────────────
  const {
    items, setItems, total, setTotal, offset, setOffset, loading, error, setError,
    statusTab, handleTabChange, mineOnly, handleMineOnlyChange,
    hasActive, limit, totalPages, currentPage,
    expandedErrors, toggleExpandError, load, invalidateLoads, getLatestViewParameters,
  } = useHistoryData();

  const [expandedSql, setExpandedSql] = useState<Set<string>>(new Set());

  // ── Client-side filtering & sorting ─────────────────────────────
  const [search, setSearch] = useState('');
  const [sourceFilter, setSourceFilter] = useState('');
  const [indexRecLoading, setIndexRecLoading] = useState(false);
  const [indexRecError, setIndexRecError] = useState<string | null>(null);
  const [indexRecData, setIndexRecData] = useState<IndexRecommendationResponse | null>(null);

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
      });
  }, [items, search, sourceFilter]);

  // ── Selection ────────────────────────────────────────────────────
  const selectableIds = useMemo(
    () => filteredItems
      .filter(isDeletableHistoryItem)
      .map((i) => i.jobId),
    [filteredItems],
  );
  const selection = useSelectionManager(selectableIds);

  // ── Batch delete ─────────────────────────────────────────────────
  const [batchDeleting, setBatchDeleting] = useState(false);
  const [confirmBatchDelete, setConfirmBatchDelete] = useState(false);

  useEffect(() => {
    if (selection.selectedCount === 0) setConfirmBatchDelete(false);
  }, [selection.selectedCount]);

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

  // ── Query-history index recommendations ─────────────────────────
  const handleGenerateIndexRecommendations = useCallback(async () => {
    setIndexRecLoading(true);
    setIndexRecError(null);
    try {
      const data = await fetchIndexRecommendations({
        days: 30,
        maxLogs: 300,
        maxPatterns: 25,
      });
      setIndexRecData(data);
    } catch (err: any) {
      setIndexRecError(err.response?.data?.error || 'Failed to generate index recommendations');
    } finally {
      setIndexRecLoading(false);
    }
  }, []);

  // ── Cancel active job ────────────────────────────────────────────
  const [cancellingId, setCancellingId] = useState<string | null>(null);

  const handleCancel = async (jobId: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setCancellingId(jobId);
    try {
      const updated = await cancelJob(jobId);
      setItems((prev) => prev.map((item) => item.jobId === jobId ? {
        ...item,
        status: updated.status,
        progressMessage: updated.progressMessage,
        startedAt: updated.startedAt,
        completedAt: updated.completedAt,
      } : item));
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
  const [historySuggestions, setHistorySuggestions] = useState<HistorySuggestionsResponse | null>(null);
  const [historySuggestionsLoading, setHistorySuggestionsLoading] = useState(false);
  const [historySuggestionsError, setHistorySuggestionsError] = useState<string | null>(null);

  const openModal = useCallback(async (item: HistoryItem) => {
    if (item.status !== 'completed') return;
    navigate(`/history/${item.jobId}`, { replace: true });
    setModalItem(item);
    setModalJob(null);
    setModalLoading(true);
    setHistorySuggestions(null);
    setHistorySuggestionsError(null);
    try {
      const data = await checkJobStatus(item.jobId);
      setModalJob(data);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to load results');
    } finally {
      setModalLoading(false);
    }
  }, [navigate]);

  // Open modal when navigating directly to /history/:jobId
  useEffect(() => {
    if (jobIdParam && items.length > 0 && !modalItem) {
      const target = items.find((i) => i.jobId === jobIdParam);
      if (target) openModal(target);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [jobIdParam, items]);

  const closeModal = useCallback(() => {
    setModalItem(null);
    setModalJob(null);
    setHistorySuggestions(null);
    setHistorySuggestionsError(null);
    navigate('/history', { replace: true });
  }, [navigate]);

  // Preserve the latest committed or synchronously derived state across async deletions.
  const deletionSnapshotRef = useRef({
    items,
    total,
    offset,
    limit,
    modalJobId: modalItem?.jobId ?? null,
  });

  useLayoutEffect(() => {
    deletionSnapshotRef.current = {
      items,
      total,
      offset,
      limit,
      modalJobId: modalItem?.jobId ?? null,
    };
  }, [items, total, offset, limit, modalItem?.jobId]);

  const applySuccessfulDeletions = useCallback(async (
    deletedIds: string[],
    deletionView: HistoryViewParameters,
  ) => {
    if (deletedIds.length === 0) return;

    invalidateLoads();
    const latestView = getLatestViewParameters();
    const sameView = deletionView.offset === latestView.offset
      && deletionView.statusTab === latestView.statusTab
      && deletionView.mineOnly === latestView.mineOnly;
    const current = deletionSnapshotRef.current;

    if (sameView) {
      const next = deriveHistoryDeletionState(
        current.items,
        current.total,
        current.offset,
        current.limit,
        deletedIds,
        current.modalJobId,
      );
      deletionSnapshotRef.current = {
        items: next.items,
        total: next.total,
        offset: next.offset,
        limit: current.limit,
        modalJobId: next.closeModal ? null : current.modalJobId,
      };
      setItems(next.items);
      setTotal(next.total);
      setOffset(next.offset);
      if (next.closeModal) closeModal();
    } else if (current.modalJobId !== null && deletedIds.includes(current.modalJobId)) {
      closeModal();
    }

    selection.removeIds(deletedIds);
    await load();
  }, [
    closeModal,
    getLatestViewParameters,
    invalidateLoads,
    load,
    selection.removeIds,
    setItems,
    setOffset,
    setTotal,
  ]);

  const handleDeleteSelected = async () => {
    const ids = selectableIds.filter((id) => selection.selectedIds.has(id));
    if (!ids.length) return;
    const deletionView = getLatestViewParameters();
    setBatchDeleting(true);
    try {
      const results = await Promise.allSettled(ids.map((id) => deleteHistoryJob(id)));
      const deletedIds = ids.filter((_, idx) => results[idx].status === 'fulfilled');
      const failedCount = ids.length - deletedIds.length;
      await applySuccessfulDeletions(deletedIds, deletionView);
      if (failedCount > 0) {
        setError(`Deleted ${deletedIds.length}, failed to delete ${failedCount}.`);
      }
    } finally {
      setBatchDeleting(false);
      setConfirmBatchDelete(false);
    }
  };

  const handleDelete = async (jobId: string) => {
    const deletionView = getLatestViewParameters();
    setConfirmDeleteId(null);
    setDeletingId(jobId);
    try {
      await deleteHistoryJob(jobId);
      await applySuccessfulDeletions([jobId], deletionView);
    } catch (err: any) {
      setError(err.response?.data?.error || 'Delete failed');
    } finally {
      setDeletingId(null);
    }
  };

  const handleGenerateHistorySuggestions = useCallback(async () => {
    if (!modalItem) return;

    setHistorySuggestionsLoading(true);
    setHistorySuggestionsError(null);
    try {
      const data = await fetchHistorySuggestions(modalItem.jobId);
      setHistorySuggestions(data);
    } catch (err: any) {
      setHistorySuggestionsError(err.response?.data?.error || 'Failed to generate related query suggestions');
    } finally {
      setHistorySuggestionsLoading(false);
    }
  }, [modalItem]);

  const handleRunHistorySuggestion = useCallback((suggestion: string) => {
    navigate(`/ask?q=${encodeURIComponent(suggestion)}`);
  }, [navigate]);

  const handleAskHistoryFollowUp = useCallback((jobId: string) => {
    navigate(`/ask?followUpJobId=${encodeURIComponent(jobId)}`);
  }, [navigate]);

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
      <div className="flex items-center justify-between gap-3 mb-6">
        <div className="flex items-center gap-3 min-w-0">
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

        <button
          onClick={handleGenerateIndexRecommendations}
          disabled={indexRecLoading}
          className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-folio-200 text-folio-700 bg-folio-50 hover:bg-folio-100 disabled:opacity-60 disabled:cursor-not-allowed text-sm font-medium whitespace-nowrap"
          title="Analyze recent query history and ask Gemini for index recommendations"
        >
          <Lightbulb size={14} className={indexRecLoading ? 'animate-pulse' : ''} />
          {indexRecLoading ? 'Analyzing History...' : 'Index Suggestions'}
        </button>
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

      {indexRecError && (
        <div className="bg-red-50 border border-red-200 rounded p-3 mb-4 text-red-700 text-sm">
          {indexRecError}
        </div>
      )}

      {indexRecData && (
        <div className="bg-white border rounded-lg p-4 mb-4">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="text-sm font-semibold text-gray-800 flex items-center gap-2">
                <Lightbulb size={14} className="text-amber-500" />
                {indexRecData.recommendationSource === 'heuristic'
                  ? 'Heuristic Index Recommendations'
                  : 'Gemini Index Recommendations'}
              </h2>
              <p className="text-xs text-gray-500 mt-1">
                Generated {new Date(indexRecData.generatedAt).toLocaleString()} •
                {' '}{indexRecData.workload.eligibleLogs} eligible logs •
                {' '}{indexRecData.workload.uniqueQueryPatterns} query patterns
              </p>
            </div>
            <button
              onClick={() => setIndexRecData(null)}
              className="text-xs text-gray-500 hover:text-gray-700"
            >
              Dismiss
            </button>
          </div>

          {indexRecData.summary && (
            <p className="text-sm text-gray-700 mt-3">{indexRecData.summary}</p>
          )}

          {indexRecData.warnings && indexRecData.warnings.length > 0 && (
            <div className="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">
              {indexRecData.warnings.join(' | ')}
            </div>
          )}

          {indexRecData.recommendations.length === 0 ? (
            <div className="mt-3 text-sm text-gray-600">
              No index recommendations were produced for the selected history window.
            </div>
          ) : (
            <div className="mt-3 space-y-3">
              {indexRecData.recommendations.map((rec, idx) => (
                <div key={`${rec.table}-${rec.columns.join('-')}-${idx}`} className="border rounded p-3 bg-gray-50">
                  <div className="flex items-center gap-2 text-sm">
                    <span className="font-semibold text-gray-800">{rec.table}</span>
                    <span className="text-gray-500">({rec.indexType})</span>
                    <span className={`text-xs px-2 py-0.5 rounded-full ${
                      rec.confidence === 'high'
                        ? 'bg-emerald-100 text-emerald-700'
                        : rec.confidence === 'low'
                          ? 'bg-amber-100 text-amber-700'
                          : 'bg-blue-100 text-blue-700'
                    }`}>
                      {rec.confidence}
                    </span>
                  </div>
                  <div className="text-xs text-gray-600 mt-1">
                    Columns: <span className="font-mono">{rec.columns.join(', ')}</span>
                  </div>
                  {rec.reason && <div className="text-sm text-gray-700 mt-2">{rec.reason}</div>}
                  {rec.createIndexSql && (
                    <pre className="mt-2 text-xs bg-white border rounded p-2 overflow-x-auto">
{rec.createIndexSql}
                    </pre>
                  )}
                </div>
              ))}
            </div>
          )}

          {indexRecData.notes?.length > 0 && (
            <div className="mt-3 text-xs text-gray-500">
              Notes: {indexRecData.notes.join(' | ')}
            </div>
          )}
        </div>
      )}

      {/* Filters + batch actions */}
      <HistoryToolbar
        search={search}
        onSearchChange={setSearch}
        sourceFilter={sourceFilter}
        onSourceFilterChange={setSourceFilter}
        mineOnly={mineOnly}
        onMineOnlyChange={handleMineOnlyChange}
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
        expandedSql={expandedSql}
        copiedSqlId={copiedSqlId}
        selectedIds={selection.selectedIds}
        onOpen={openModal}
        onCancel={handleCancel}
        onToggleError={(jobId, e) => { e.stopPropagation(); toggleExpandError(jobId); }}
        onToggleSql={(jobId, e) => {
          e.stopPropagation();
          setExpandedSql((prev) => {
            const next = new Set(prev);
            if (next.has(jobId)) next.delete(jobId); else next.add(jobId);
            return next;
          });
        }}
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
          suggestions={historySuggestions?.suggestions ?? []}
          suggestionSource={historySuggestions?.suggestionSource ?? null}
          suggestionWarnings={historySuggestions?.warnings ?? []}
          suggestionsLoading={historySuggestionsLoading}
          suggestionsError={historySuggestionsError}
          onGenerateSuggestions={handleGenerateHistorySuggestions}
          onRunSuggestion={handleRunHistorySuggestion}
          onAskFollowUp={handleAskHistoryFollowUp}
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
