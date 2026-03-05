import { useState, useEffect, useCallback, useRef } from 'react';
import {
  History as HistoryIcon, Clock, Database, Eye, ChevronLeft, ChevronRight,
  User, Search, X, Download, Copy, Check, ChevronDown, ChevronUp,
  Loader2, Code2, Pencil, Bookmark, LayoutDashboard, CheckCircle2,
  XCircle, AlertCircle, Activity, StopCircle,
} from 'lucide-react';
import { fetchQueryHistory, checkJobStatus, renameHistoryJob, saveQuery, cancelJob } from '../api/client';
import { useAuth } from '../hooks/useAuth';
import type { HistoryItem, JobStatus, JobStatusResponse, SavedQuery } from '../types';

// ─── Helpers ───────────────────────────────────────────────────────────────────

function fmtTime(ms: number) {
  if (ms >= 60000) return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
  if (ms >= 1000) return `${(ms / 1000).toFixed(1)}s`;
  return `${ms}ms`;
}

function fmtDate(iso: string) {
  const d = new Date(iso);
  return (
    d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
    ' ' +
    d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  );
}

// ─── Status badge ─────────────────────────────────────────────────────────────

const STATUS_CONFIG: Record<JobStatus, { label: string; cls: string; icon: React.ElementType }> = {
  pending:   { label: 'Queued',    cls: 'bg-amber-100 text-amber-700',   icon: Clock },
  running:   { label: 'Running',   cls: 'bg-blue-100 text-blue-700',     icon: Loader2 },
  completed: { label: 'Completed', cls: 'bg-green-100 text-green-700',   icon: CheckCircle2 },
  failed:    { label: 'Failed',    cls: 'bg-red-100 text-red-700',       icon: XCircle },
  cancelled: { label: 'Cancelled', cls: 'bg-gray-100 text-gray-500',     icon: StopCircle },
};

function StatusBadge({ status }: { status: JobStatus }) {
  const cfg = STATUS_CONFIG[status] ?? STATUS_CONFIG.cancelled;
  const Icon = cfg.icon;
  return (
    <span className={`inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded ${cfg.cls}`}>
      <Icon size={11} className={status === 'running' ? 'animate-spin' : ''} />
      {cfg.label}
    </span>
  );
}

const SOURCE_STYLES: Record<string, string> = {
  nl:      'bg-purple-100 text-purple-700',
  builder: 'bg-blue-100 text-blue-700',
  manual:  'bg-gray-100 text-gray-600',
  report:  'bg-amber-100 text-amber-700',
};

function SourceBadge({ source }: { source: string }) {
  const label = source === 'nl' ? 'Ask AI' : source === 'builder' ? 'Builder' : source;
  return (
    <span className={`inline-flex items-center text-xs font-medium px-2 py-0.5 rounded ${SOURCE_STYLES[source] ?? 'bg-gray-100 text-gray-600'}`}>
      {label}
    </span>
  );
}

function downloadCsv(job: JobStatusResponse, name: string | null) {
  if (!job.columns || !job.rows) return;
  const escape = (v: unknown) => {
    const s = v == null ? '' : String(v);
    return s.includes(',') || s.includes('"') || s.includes('\n')
      ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const header = job.columns.map(escape).join(',');
  const rows = job.rows.map((r) => job.columns!.map((c) => escape(r[c])).join(','));
  const csv = [header, ...rows].join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${(name || 'query').replace(/[^a-z0-9_-]/gi, '_')}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// ─── Save-to-library dialog ───────────────────────────────────────────────────

function SaveQueryDialog({
  item, initialPinned, onClose, onSaved,
}: {
  item: HistoryItem;
  initialPinned: boolean;
  onClose: () => void;
  onSaved: (sq: SavedQuery, pinned: boolean) => void;
}) {
  const [name, setName] = useState(item.name || '');
  const [description, setDescription] = useState('');
  const [isPinned, setIsPinned] = useState(initialPinned);
  const [saving, setSaving] = useState(false);
  const [savedOk, setSavedOk] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const nameRef = useRef<HTMLInputElement>(null);

  useEffect(() => { nameRef.current?.focus(); }, []);
  useEffect(() => {
    const h = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  }, [onClose]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) { setErr('Name is required'); return; }
    setSaving(true);
    setErr(null);
    try {
      const source = (item.source === 'nl' || item.source === 'builder') ? item.source : 'builder';
      const sq = await saveQuery({
        name: name.trim(),
        description: description.trim() || undefined,
        generatedSql: item.sql,
        queryDefinition: { sql: item.sql },
        source,
        isPinned,
      });
      setSavedOk(true);
      setTimeout(() => { onSaved(sq, isPinned); onClose(); }, 900);
    } catch (e: any) {
      setErr(e.response?.data?.error || 'Failed to save');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[60] bg-black/40 flex items-center justify-center p-4"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md">
        <div className="px-6 py-4 border-b flex items-center justify-between">
          <h2 className="font-semibold text-gray-800 flex items-center gap-2">
            {isPinned
              ? <><LayoutDashboard size={16} className="text-folio-600" /> Add to Dashboard</>
              : <><Bookmark size={16} className="text-folio-600" /> Save to Library</>}
          </h2>
          <button onClick={onClose} className="p-1 text-gray-400 hover:text-gray-600 rounded"><X size={16} /></button>
        </div>
        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Name <span className="text-red-500">*</span></label>
            <input
              ref={nameRef}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="My recurring query…"
              className="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description <span className="text-xs text-gray-400 ml-1">optional</span></label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={2}
              placeholder="What does this query do?"
              className="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none resize-none"
            />
          </div>
          <label className="flex items-center gap-2.5 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={isPinned}
              onChange={(e) => setIsPinned(e.target.checked)}
              className="w-4 h-4 rounded text-folio-600 focus:ring-folio-400"
            />
            <span className="text-sm text-gray-700 flex items-center gap-1.5">
              <LayoutDashboard size={14} className="text-folio-500" /> Pin to Dashboard
            </span>
          </label>
          {err && <p className="text-red-600 text-xs">{err}</p>}
          <div className="flex items-center justify-end gap-2 pt-1">
            <button type="button" onClick={onClose} className="px-4 py-2 text-sm border rounded-lg hover:bg-gray-50">Cancel</button>
            <button
              type="submit"
              disabled={saving || savedOk}
              className="px-4 py-2 text-sm bg-folio-600 text-white rounded-lg hover:bg-folio-700 disabled:opacity-60 flex items-center gap-1.5 transition-colors"
            >
              {savedOk
                ? <><CheckCircle2 size={14} className="text-green-300" /> Saved!</>
                : saving ? <><Loader2 size={14} className="animate-spin" /> Saving…</> : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Results modal ─────────────────────────────────────────────────────────────

function ResultsModal({
  item, job, loading, onClose, onRename, onSave,
}: {
  item: HistoryItem;
  job: JobStatusResponse | null;
  loading: boolean;
  onClose: () => void;
  onRename: (newName: string) => void;
  onSave: (initialPinned: boolean) => void;
}) {
  const [sqlOpen, setSqlOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const [renaming, setRenaming] = useState(false);
  const [renameVal, setRenameVal] = useState(item.name ?? '');
  const [renameSaving, setRenameSaving] = useState(false);
  const renameInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => { setRenameVal(item.name ?? ''); }, [item.name]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape' && !renaming) onClose(); };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [onClose, renaming]);

  useEffect(() => { if (renaming) renameInputRef.current?.focus(); }, [renaming]);

  const commitRename = async () => {
    const val = renameVal.trim();
    if (val === (item.name ?? '')) { setRenaming(false); return; }
    setRenameSaving(true);
    try {
      await renameHistoryJob(item.jobId, val);
      onRename(val || '');
    } finally {
      setRenameSaving(false);
      setRenaming(false);
    }
  };

  const handleCopySql = () => {
    const sql = job?.sql || item.sql;
    if (sql) {
      navigator.clipboard.writeText(sql);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-xl shadow-2xl flex flex-col w-full max-w-6xl max-h-[90vh] overflow-hidden">
        {/* Header */}
        <div className="flex items-start justify-between px-6 py-4 border-b bg-gray-50 flex-shrink-0">
          <div className="flex-1 min-w-0 pr-4">
            <div className="flex items-center gap-2 flex-wrap">
              <SourceBadge source={item.source} />
              {renaming ? (
                <form
                  onSubmit={(e) => { e.preventDefault(); commitRename(); }}
                  className="flex items-center gap-1.5 flex-1 min-w-0"
                >
                  <input
                    ref={renameInputRef}
                    value={renameVal}
                    onChange={(e) => setRenameVal(e.target.value)}
                    onBlur={commitRename}
                    onKeyDown={(e) => { if (e.key === 'Escape') { setRenaming(false); setRenameVal(item.name ?? ''); } }}
                    disabled={renameSaving}
                    placeholder="Query name…"
                    className="flex-1 px-2 py-0.5 text-sm font-semibold border rounded border-folio-400 focus:ring-1 focus:ring-folio-300 outline-none min-w-0"
                  />
                  {renameSaving && <Loader2 size={13} className="animate-spin text-folio-500 flex-shrink-0" />}
                </form>
              ) : (
                <button
                  onClick={() => setRenaming(true)}
                  className="flex items-center gap-1.5 group/name min-w-0"
                  title="Click to rename"
                >
                  <h2 className="text-base font-semibold text-gray-800 truncate">
                    {item.name ?? <span className="italic text-gray-400 font-normal">Unnamed query</span>}
                  </h2>
                  <Pencil size={12} className="text-gray-300 group-hover/name:text-folio-500 flex-shrink-0 transition-colors" />
                </button>
              )}
            </div>
            <div className="flex items-center gap-4 mt-1.5 text-xs text-gray-400 flex-wrap">
              {(item.completedAt ?? item.createdAt) && (
                <span className="flex items-center gap-1"><Clock size={11} />{fmtDate(item.completedAt ?? item.createdAt!)}</span>
              )}
              <span>{item.rowCount.toLocaleString()} rows</span>
              <span>{fmtTime(item.executionTimeMs)}</span>
              {item.runBy && (
                <span className="flex items-center gap-1"><User size={11} />{item.runBy}</span>
              )}
            </div>
          </div>
          <div className="flex items-center gap-2 flex-shrink-0">
            <button
              onClick={() => onSave(false)}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 text-gray-600 rounded hover:bg-gray-100 transition-colors"
              title="Save to Library"
            >
              <Bookmark size={14} /> Save
            </button>
            <button
              onClick={() => onSave(true)}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 text-gray-600 rounded hover:bg-gray-100 transition-colors"
              title="Pin to Dashboard"
            >
              <LayoutDashboard size={14} /> Dashboard
            </button>
            {job && (
              <button
                onClick={() => downloadCsv(job, item.name)}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-folio-600 text-white rounded hover:bg-folio-700 transition-colors"
              >
                <Download size={14} /> CSV
              </button>
            )}
            <button
              onClick={onClose}
              className="p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-200 rounded-lg transition-colors"
              title="Close (Esc)"
            >
              <X size={18} />
            </button>
          </div>
        </div>

        {/* SQL collapsible */}
        <div className="border-b flex-shrink-0">
          <button
            onClick={() => setSqlOpen((o) => !o)}
            className="w-full flex items-center justify-between px-6 py-2.5 text-xs text-gray-500 hover:bg-gray-50 transition-colors"
          >
            <span className="flex items-center gap-1.5 font-medium"><Code2 size={13} /> SQL</span>
            <div className="flex items-center gap-2">
              <span
                role="button"
                onClick={(e) => { e.stopPropagation(); handleCopySql(); }}
                className="flex items-center gap-1 px-2 py-0.5 rounded border border-gray-200 hover:bg-white transition-colors cursor-pointer"
              >
                {copied
                  ? <><Check size={11} className="text-green-500" /> Copied</>
                  : <><Copy size={11} /> Copy</>}
              </span>
              {sqlOpen ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
            </div>
          </button>
          {sqlOpen && (
            <div className="px-6 pb-3">
              <pre className="text-xs font-mono text-gray-600 bg-gray-50 p-3 rounded border border-gray-100 max-h-40 overflow-auto whitespace-pre-wrap">
                {job?.sql ?? item.sql}
              </pre>
            </div>
          )}
        </div>

        {/* Results body */}
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center h-48 gap-3 text-gray-400">
              <Loader2 size={20} className="animate-spin text-folio-600" />
              <span className="text-sm">Loading results…</span>
            </div>
          ) : job?.columns && job?.rows ? (
            <>
              <table className="w-full text-xs border-separate border-spacing-0">
                <thead className="bg-gray-50 sticky top-0 z-10">
                  <tr>
                    {job.columns.map((col) => (
                      <th
                        key={col}
                        className="text-left px-3 py-2 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap"
                      >
                        {col}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {job.rows.map((row, i) => (
                    <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-gray-50/50'}>
                      {job.columns!.map((col) => (
                        <td
                          key={col}
                          className="px-3 py-1.5 text-gray-700 whitespace-nowrap max-w-xs truncate border-b border-gray-100"
                          title={row[col] != null ? String(row[col]) : ''}
                        >
                          {row[col] != null
                            ? String(row[col])
                            : <span className="text-gray-300 italic">null</span>}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
              {job.rows.length >= 200 && (
                <div className="px-4 py-3 text-xs text-gray-400 text-center border-t bg-gray-50">
                  Showing first {job.rows.length.toLocaleString()} rows — download CSV for full dataset
                </div>
              )}
            </>
          ) : (
            <div className="flex items-center justify-center h-48 text-gray-400 text-sm">
              No result data available.
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── Main page ─────────────────────────────────────────────────────────────────

type SortKey = 'completedAt' | 'rowCount' | 'executionTimeMs';

const STATUS_TABS: { value: string; label: string; icon: React.ElementType }[] = [
  { value: 'all',       label: 'All',       icon: HistoryIcon },
  { value: 'active',    label: 'Active',     icon: Activity },
  { value: 'completed', label: 'Completed',  icon: CheckCircle2 },
  { value: 'failed',    label: 'Failed',     icon: AlertCircle },
];

export default function History() {
  const { isAdmin } = useAuth();
  const [items, setItems] = useState<HistoryItem[]>([]);
  const [total, setTotal] = useState(0);
  const [offset, setOffset] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [sourceFilter, setSourceFilter] = useState<string>('');
  const [statusTab, setStatusTab] = useState<string>('all');
  const [sortKey, setSortKey] = useState<SortKey>('completedAt');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [modalItem, setModalItem] = useState<HistoryItem | null>(null);
  const [modalJob, setModalJob] = useState<JobStatusResponse | null>(null);
  const [modalLoading, setModalLoading] = useState(false);
  // Rename state
  const [renamingId, setRenamingId] = useState<string | null>(null);
  const [renameValue, setRenameValue] = useState('');
  const [renameSaving, setRenameSaving] = useState(false);
  // Save-to-library dialog
  const [savingItem, setSavingItem] = useState<HistoryItem | null>(null);
  const [saveInitialPin, setSaveInitialPin] = useState(false);
  // Cancel state
  const [cancellingId, setCancellingId] = useState<string | null>(null);
  // Inline error expansion
  const [expandedErrors, setExpandedErrors] = useState<Set<string>>(new Set());
  // Copy-SQL feedback
  const [copiedSqlId, setCopiedSqlId] = useState<string | null>(null);

  const searchRef = useRef<HTMLInputElement>(null);

  const limit = 50;

  const load = useCallback(async () => {
    try {
      setLoading(true);
      const data = await fetchQueryHistory(limit, offset, statusTab);
      setItems(data.items);
      setTotal(data.total);
      setError(null);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to load history');
    } finally {
      setLoading(false);
    }
  }, [offset, statusTab]);

  useEffect(() => { load(); }, [load]);

  // Auto-refresh every 5 s while there are pending/running jobs on the current page
  const hasActive = items.some((i) => i.status === 'pending' || i.status === 'running');
  useEffect(() => {
    if (!hasActive) return;
    const timer = setInterval(() => { load(); }, 5000);
    return () => clearInterval(timer);
  }, [hasActive, load]);

  const handleTabChange = (tab: string) => {
    setStatusTab(tab);
    setOffset(0);
    // When leaving the failed tab, collapse all error rows
    if (tab !== 'failed') setExpandedErrors(new Set());
  };

  // Auto-expand error rows when Failed tab loads
  useEffect(() => {
    if (statusTab === 'failed') {
      setExpandedErrors(new Set(items.filter((i) => i.errorMessage).map((i) => i.jobId)));
    }
  }, [statusTab, items]);

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

  const toggleError = (jobId: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setExpandedErrors((prev) => {
      const next = new Set(prev);
      if (next.has(jobId)) next.delete(jobId); else next.add(jobId);
      return next;
    });
  };

  const copySql = async (jobId: string, sql: string, e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      await navigator.clipboard.writeText(sql);
      setCopiedSqlId(jobId);
      setTimeout(() => setCopiedSqlId((prev) => (prev === jobId ? null : prev)), 2000);
    } catch { /* ignore */ }
  };

  const closeModal = useCallback(() => { setModalItem(null); setModalJob(null); }, []);

  // ── Inline rename handlers ──────────────────────────────────────
  const startRename = (item: HistoryItem, e: React.MouseEvent) => {
    e.stopPropagation();
    setRenamingId(item.jobId);
    setRenameValue(item.name ?? '');
  };

  const cancelRename = () => { setRenamingId(null); setRenameValue(''); };

  const commitRename = async (jobId: string) => {
    const val = renameValue.trim();
    const original = items.find((i) => i.jobId === jobId)?.name ?? '';
    if (val === original) { cancelRename(); return; }
    setRenameSaving(true);
    try {
      await renameHistoryJob(jobId, val);
      setItems((prev) => prev.map((i) => i.jobId === jobId ? { ...i, name: val || null } : i));
      if (modalItem?.jobId === jobId) setModalItem((m) => m ? { ...m, name: val || null } : m);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Rename failed');
    } finally {
      setRenameSaving(false);
      cancelRename();
    }
  };

  // ── Modal-triggered rename ──────────────────────────────────────
  const handleModalRename = async (newName: string) => {
    if (!modalItem) return;
    setItems((prev) => prev.map((i) => i.jobId === modalItem.jobId ? { ...i, name: newName || null } : i));
    setModalItem((m) => m ? { ...m, name: newName || null } : m);
  };

  // ── Save-to-library ─────────────────────────────────────────────
  const openSaveDialog = (item: HistoryItem, pinned: boolean, e?: React.MouseEvent) => {
    e?.stopPropagation();
    setSavingItem(item);
    setSaveInitialPin(pinned);
    // Close modal so we don't stack two modals
    setModalItem(null);
    setModalJob(null);
  };

  const filtered = items
    .filter((item) => {
      const q = search.toLowerCase();
      if (q && !((item.name ?? '').toLowerCase().includes(q) || item.sql.toLowerCase().includes(q) || (item.runBy ?? '').toLowerCase().includes(q))) return false;
      if (sourceFilter && item.source !== sourceFilter) return false;
      return true;
    })
    .sort((a, b) => {
      // completedAt may be null for pending/running — fall back to createdAt
      const getVal = (item: HistoryItem) => {
        if (sortKey === 'completedAt') return item.completedAt ?? item.createdAt;
        return item[sortKey] as string | number;
      };
      const av = getVal(a);
      const bv = getVal(b);
      const al = typeof av === 'string' ? av.toLowerCase() : (av ?? 0);
      const bl = typeof bv === 'string' ? bv.toLowerCase() : (bv ?? 0);
      if (al < bl) return sortDir === 'asc' ? -1 : 1;
      if (al > bl) return sortDir === 'asc' ? 1 : -1;
      return 0;
    });

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    else { setSortKey(key); setSortDir('desc'); }
  };

  const SortIcon = ({ col }: { col: SortKey }) =>
    sortKey === col
      ? (sortDir === 'asc' ? <ChevronUp size={12} /> : <ChevronDown size={12} />)
      : <ChevronDown size={12} className="opacity-30" />;

  const totalPages = Math.ceil(total / limit);
  const currentPage = Math.floor(offset / limit) + 1;

  return (
    <div className="max-w-screen-xl mx-auto p-6">
      {/* Page title */}
      <div className="flex items-center gap-3 mb-6">
        <HistoryIcon className="text-folio-600 flex-shrink-0" size={22} />
        <h1 className="text-2xl font-bold text-gray-800">Query History</h1>
        <span className="text-sm text-gray-400 ml-1">{total} {total === 1 ? 'query' : 'queries'}</span>
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

      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-3 mb-4 text-red-700 text-sm">{error}</div>
      )}

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3 mb-4">
        <div className="relative flex-1 min-w-48">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            ref={searchRef}
            type="text"
            placeholder="Search by name, SQL, or user…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-8 pr-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
          />
          {search && (
            <button onClick={() => setSearch('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
              <X size={13} />
            </button>
          )}
        </div>
        <select
          value={sourceFilter}
          onChange={(e) => setSourceFilter(e.target.value)}
          className="text-sm border rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-folio-300 outline-none"
        >
          <option value="">All sources</option>
          <option value="nl">Ask AI</option>
          <option value="builder">Builder</option>
          <option value="manual">Manual</option>
          <option value="report">Report</option>
        </select>
        {(search || sourceFilter) && (
          <span className="text-xs text-gray-500">{filtered.length} match{filtered.length !== 1 ? 'es' : ''}</span>
        )}
      </div>

      {/* Table */}
      {loading ? (
        <div className="flex items-center justify-center py-16 gap-3 text-gray-400">
          <Loader2 size={20} className="animate-spin text-folio-600" />
          <span className="text-sm">Loading history…</span>
        </div>
      ) : filtered.length === 0 ? (
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
      ) : (
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
          <table className="w-full text-sm border-separate border-spacing-0">
            <thead>
              <tr className="bg-gray-50 text-left">
                <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 w-[34%]">Query</th>
                <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap">Status</th>
                <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap">Source</th>
                <th
                  className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap cursor-pointer select-none hover:text-folio-700"
                  onClick={() => toggleSort('rowCount')}
                >
                  <span className="flex items-center gap-1">Rows <SortIcon col="rowCount" /></span>
                </th>
                <th
                  className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap cursor-pointer select-none hover:text-folio-700"
                  onClick={() => toggleSort('executionTimeMs')}
                >
                  <span className="flex items-center gap-1">Time <SortIcon col="executionTimeMs" /></span>
                </th>
                {isAdmin && (
                  <th className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap">Run by</th>
                )}
                <th
                  className="px-4 py-3 font-semibold text-gray-600 border-b border-gray-200 whitespace-nowrap cursor-pointer select-none hover:text-folio-700"
                  onClick={() => toggleSort('completedAt')}
                >
                  <span className="flex items-center gap-1">Date <SortIcon col="completedAt" /></span>
                </th>
                <th className="px-4 py-3 border-b border-gray-200 w-16" />
              </tr>
            </thead>
            <tbody>
              {filtered.map((item, i) => {
                const isActive = item.status === 'pending' || item.status === 'running';
                const isFailed = item.status === 'failed';
                const isCompleted = item.status === 'completed';
                const errExpanded = expandedErrors.has(item.jobId);
                const rowBg = i % 2 === 0 ? 'bg-white' : 'bg-gray-50/40';
                const colSpan = isAdmin ? 8 : 7;
                return (
                  <>
                    <tr
                      key={item.jobId}
                      className={`group transition-colors ${rowBg} ${
                        isCompleted ? 'cursor-pointer hover:bg-folio-50' : 'cursor-default'
                      }`}
                      onClick={() => renamingId !== item.jobId && isCompleted && openModal(item)}
                    >
                      {/* Query name + SQL preview */}
                      <td className="px-4 py-3 border-b border-gray-100">
                        {renamingId === item.jobId ? (
                          <form
                            onSubmit={(e) => { e.preventDefault(); commitRename(item.jobId); }}
                            onClick={(e) => e.stopPropagation()}
                          >
                            <input
                              autoFocus
                              value={renameValue}
                              onChange={(e) => setRenameValue(e.target.value)}
                              onBlur={() => commitRename(item.jobId)}
                              onKeyDown={(e) => { if (e.key === 'Escape') cancelRename(); }}
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
                            <p className="text-xs text-gray-400 font-mono mt-0.5 line-clamp-1 truncate">{item.sql}</p>
                          </>
                        )}
                      </td>
                      <td className="px-4 py-3 border-b border-gray-100 whitespace-nowrap">
                        <StatusBadge status={item.status} />
                      </td>
                      <td className="px-4 py-3 border-b border-gray-100"><SourceBadge source={item.source} /></td>
                      <td className="px-4 py-3 border-b border-gray-100 text-gray-600 tabular-nums whitespace-nowrap">
                        {isCompleted ? item.rowCount.toLocaleString() : '—'}
                      </td>
                      <td className="px-4 py-3 border-b border-gray-100 text-gray-600 tabular-nums whitespace-nowrap">
                        {item.executionTimeMs > 0 ? fmtTime(item.executionTimeMs) : '—'}
                      </td>
                      {isAdmin && (
                        <td className="px-4 py-3 border-b border-gray-100 text-gray-500 text-xs whitespace-nowrap">
                          {item.runBy
                            ? <span className="flex items-center gap-1"><User size={11} />{item.runBy}</span>
                            : '—'}
                        </td>
                      )}
                      <td className="px-4 py-3 border-b border-gray-100 text-gray-500 text-xs whitespace-nowrap">
                        {item.completedAt
                          ? fmtDate(item.completedAt)
                          : item.startedAt
                            ? <span className="text-blue-500">{fmtDate(item.startedAt)}</span>
                            : <span className="text-amber-500">{fmtDate(item.createdAt)}</span>}
                      </td>
                      {/* Row action buttons */}
                      <td
                        className="px-3 py-3 border-b border-gray-100"
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div className="flex items-center justify-end gap-0.5">
                          {isActive && (
                            <button
                              onClick={(e) => handleCancel(item.jobId, e)}
                              disabled={cancellingId === item.jobId}
                              title="Cancel query"
                              className="flex items-center gap-1 px-2 py-1 text-xs rounded text-red-600 hover:bg-red-50 border border-red-200 transition-colors disabled:opacity-50"
                            >
                              {cancellingId === item.jobId
                                ? <Loader2 size={11} className="animate-spin" />
                                : <XCircle size={11} />}
                              Cancel
                            </button>
                          )}
                          {isFailed && (
                            <button
                              onClick={(e) => toggleError(item.jobId, e)}
                              title={errExpanded ? 'Hide SQL and error' : 'Show SQL and error details'}
                              className="flex items-center gap-1 px-2 py-1 text-xs rounded text-red-600 hover:bg-red-50 border border-red-200 transition-colors"
                            >
                              <AlertCircle size={11} />
                              {errExpanded ? 'Hide details' : 'Details'}
                            </button>
                          )}
                          {isCompleted && (
                            <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                              <button onClick={(e) => startRename(item, e)} title="Rename"
                                className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors">
                                <Pencil size={13} />
                              </button>
                              <button onClick={(e) => openSaveDialog(item, false, e)} title="Save to Library"
                                className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors">
                                <Bookmark size={13} />
                              </button>
                              <button onClick={(e) => openSaveDialog(item, true, e)} title="Add to Dashboard"
                                className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors">
                                <LayoutDashboard size={13} />
                              </button>
                              <button onClick={() => openModal(item)} title="View results"
                                className="p-1.5 rounded text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors">
                                <Eye size={13} />
                              </button>
                            </div>
                          )}
                        </div>
                      </td>
                    </tr>
                    {/* Inline error row — full SQL + error message */}
                    {isFailed && errExpanded && (
                      <tr key={`${item.jobId}-err`} className={rowBg}>
                        <td colSpan={colSpan} className="px-4 pb-4 border-b border-gray-100">
                          <div className="mt-1 space-y-3">
                            {/* Full SQL */}
                            <div>
                              <div className="flex items-center justify-between mb-1">
                                <span className="text-xs font-semibold text-gray-500 uppercase tracking-wide">SQL</span>
                                <button
                                  onClick={(e) => copySql(item.jobId, item.sql, e)}
                                  className="flex items-center gap-1 text-xs text-gray-400 hover:text-folio-600 px-2 py-0.5 rounded hover:bg-folio-50 transition-colors"
                                >
                                  {copiedSqlId === item.jobId
                                    ? <><Check size={11} className="text-green-500" /> Copied</>  
                                    : <><Copy size={11} /> Copy SQL</>}
                                </button>
                              </div>
                              <pre className="bg-gray-900 text-green-300 text-xs font-mono rounded p-3 whitespace-pre-wrap break-all max-h-48 overflow-y-auto leading-relaxed">{item.sql}</pre>
                            </div>
                            {/* Error message */}
                            {item.errorMessage && (
                              <div>
                                <div className="text-xs font-semibold text-red-500 uppercase tracking-wide mb-1">Error</div>
                                <pre className="bg-red-50 border border-red-200 text-red-700 text-xs font-mono rounded p-3 whitespace-pre-wrap break-all max-h-40 overflow-y-auto leading-relaxed">{item.errorMessage}</pre>
                              </div>
                            )}
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
      )}

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
          <span className="text-sm text-gray-500">Page {currentPage} of {totalPages}</span>
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
        <ResultsModal
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
