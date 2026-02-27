import { useState, useEffect, useRef, useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import {
  fetchDashboard, reorderDashboard, hideDashboardItem, showDashboardItem,
  toggleGlobal, togglePin, checkJobStatus, refreshDashboardCard, saveDashboardDisplay,
} from '../api/client';
import { useAuth } from '../hooks/useAuth';
import { useJobPolling } from '../hooks/useJobPolling';
import ResultsModal from '../components/ResultsModal';
import ChartPanel from '../components/ChartPanel';
import type { ChartType } from '../components/ChartPanel';
import type { DashboardItem, ExecuteResponse, ChartConfig } from '../types';
import {
  LayoutDashboard, PinOff, Maximize2, Wrench, MessageSquare, FileBarChart,
  Loader2, AlertCircle, Sparkles, Globe, EyeOff, Eye, GripVertical,
  ChevronDown, ChevronUp, RefreshCw, Play, Table, BarChart3, TrendingUp,
  PieChart, AreaChart,
} from 'lucide-react';

// ─── Display-type toggle helpers ─────────────────────────────────────────────

const DISPLAY_TYPES: { key: ChartType | 'table'; label: string; icon: React.ReactNode }[] = [
  { key: 'table', label: 'Table', icon: <Table size={12} /> },
  { key: 'bar',   label: 'Bar',   icon: <BarChart3 size={12} /> },
  { key: 'line',  label: 'Line',  icon: <TrendingUp size={12} /> },
  { key: 'pie',   label: 'Pie',   icon: <PieChart size={12} /> },
  { key: 'area',  label: 'Area',  icon: <AreaChart size={12} /> },
];

// ─── Single card ──────────────────────────────────────────────────────────────

function DashboardCard({
  item,
  isAdmin,
  isDragging,
  isDragOver,
  onDragStart,
  onDragOver,
  onDrop,
  onDragEnd,
  onUnpin,
  onHide,
  onToggleGlobal,
  onExpand,
}: {
  item: DashboardItem;
  isAdmin: boolean;
  isDragging: boolean;
  isDragOver: boolean;
  onDragStart: () => void;
  onDragOver: (e: React.DragEvent) => void;
  onDrop: () => void;
  onDragEnd: () => void;
  onUnpin: () => void;
  onHide: () => void;
  onToggleGlobal: () => void;
  onExpand: (data: ExecuteResponse, title: string) => void;
}) {
  // ── Cached / live results ──
  const [cachedResults, setCachedResults] = useState<ExecuteResponse | null>(null);
  const [loadingCache, setLoadingCache] = useState(false);
  const [lastJobId, setLastJobId] = useState<string | null>(item.last_job_id);

  // ── Refresh polling ──
  const [pollingJobId, setPollingJobId] = useState<string | null>(null);
  const { results: polledResults, isRunning, error: pollError } = useJobPolling(pollingJobId);

  // ── Display type ──
  const [displayType, setDisplayType] = useState<ChartType | 'table'>(item.display_type ?? 'table');
  const [chartConfig, setChartConfig] = useState<ChartConfig | null>(item.chart_config ?? null);

  // On mount: load cached result from last_job_id (no auto-run)
  useEffect(() => {
    if (!lastJobId) return;
    setLoadingCache(true);
    checkJobStatus(lastJobId)
      .then((job) => {
        if (job.status === 'completed' && job.columns && job.rows) {
          setCachedResults({
            columns: job.columns,
            rows: job.rows,
            rowCount: job.rowCount ?? job.rows.length,
            executionTimeMs: job.executionTimeMs ?? 0,
            sql: job.sql ?? '',
          });
        }
      })
      .catch(() => { /* ignore stale job errors */ })
      .finally(() => setLoadingCache(false));
  // only run when lastJobId changes (i.e. after a refresh)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastJobId]);

  // When polling completes, update the cached results and remember the new job id
  useEffect(() => {
    if (polledResults) {
      setCachedResults(polledResults);
    }
  }, [polledResults]);

  // Active results = freshly polled (while running) or cached
  const results = polledResults ?? cachedResults;

  const handleRefresh = async () => {
    try {
      const { jobId } = await refreshDashboardCard(item.id);
      setLastJobId(jobId);
      setPollingJobId(jobId);
    } catch {
      // surface via pollError
    }
  };

  const handleDisplayChange = (dt: ChartType | 'table') => {
    setDisplayType(dt);
    saveDashboardDisplay(item.id, dt === 'table' ? 'table' : dt, chartConfig);
  };

  const handleChartConfigChange = (axesCfg: { xAxis: string; yAxes: string[] }) => {
    const cfg: ChartConfig = { xAxis: axesCfg.xAxis, yAxes: axesCfg.yAxes };
    setChartConfig(cfg);
    saveDashboardDisplay(item.id, displayType === 'table' ? 'table' : displayType, cfg);
  };

  return (
    <div
      draggable
      onDragStart={onDragStart}
      onDragOver={onDragOver}
      onDrop={onDrop}
      onDragEnd={onDragEnd}
      className={`border rounded-xl bg-white shadow-sm flex flex-col transition-all select-none
        ${isDragging ? 'opacity-40 scale-[0.97] shadow-inner' : 'hover:shadow-md'}
        ${isDragOver ? 'ring-2 ring-folio-400 ring-offset-1' : ''}
      `}
    >
      {/* Card header */}
      <div className="px-3 py-2.5 border-b flex items-center gap-2">
        <GripVertical size={14} className="text-gray-300 flex-shrink-0 cursor-grab active:cursor-grabbing" />

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-1.5 flex-wrap">
            {item.source_type === 'global' && (
              <span className="inline-flex items-center gap-0.5 text-[10px] font-semibold bg-amber-50 text-amber-600 border border-amber-200 px-1.5 py-0.5 rounded">
                <Globe size={9} /> Admin
              </span>
            )}
            {item.source === 'nl' && (
              <span className="text-[10px] bg-purple-50 text-purple-600 px-1.5 py-0.5 rounded font-medium">AI</span>
            )}
          </div>
          <h3 className="font-medium text-sm truncate mt-0.5" title={item.name}>{item.name}</h3>
          {item.nl_prompt && (
            <p className="text-xs text-gray-400 truncate" title={item.nl_prompt}>
              <Sparkles size={10} className="inline mr-1 text-purple-400" />{item.nl_prompt}
            </p>
          )}
          {item.description && !item.nl_prompt && (
            <p className="text-xs text-gray-400 truncate">{item.description}</p>
          )}
        </div>

        {/* Action buttons */}
        <div className="flex items-center gap-0.5 flex-shrink-0">
          {/* Refresh / Run button */}
          {isRunning ? (
            <Loader2 size={13} className="animate-spin text-folio-600 mx-1" />
          ) : lastJobId ? (
            <button onClick={handleRefresh} className="p-1 text-gray-400 hover:text-folio-600 rounded" title="Re-run query">
              <RefreshCw size={13} />
            </button>
          ) : (
            <button onClick={handleRefresh} className="p-1 text-folio-500 hover:text-folio-700 rounded" title="Run query">
              <Play size={13} />
            </button>
          )}
          {results && (
            <button onClick={() => onExpand(results, item.name)} className="p-1 text-gray-400 hover:text-folio-600 rounded" title="Expand results">
              <Maximize2 size={13} />
            </button>
          )}
          {isAdmin && (
            <button
              onClick={onToggleGlobal}
              title={item.is_global ? 'Remove from all dashboards' : 'Push to all dashboards'}
              className={`p-1 rounded transition-colors ${item.is_global ? 'text-amber-500 hover:text-amber-700' : 'text-gray-300 hover:text-amber-500'}`}
            >
              <Globe size={13} />
            </button>
          )}
          {item.source_type === 'global' && !isAdmin && (
            <button onClick={onHide} title="Hide from my dashboard" className="p-1 text-gray-300 hover:text-gray-500 rounded">
              <EyeOff size={13} />
            </button>
          )}
          {item.source_type === 'personal' && (
            <button onClick={onUnpin} title="Remove from dashboard" className="p-1 text-gray-300 hover:text-red-500 rounded">
              <PinOff size={13} />
            </button>
          )}
        </div>
      </div>

      {/* Display type toggle */}
      <div className="flex border-b bg-gray-50 px-3 py-1.5 gap-1">
        {DISPLAY_TYPES.map(({ key, label, icon }) => (
          <button
            key={key}
            onClick={() => handleDisplayChange(key)}
            className={`flex items-center gap-1 text-[11px] px-2 py-0.5 rounded transition-colors ${
              displayType === key
                ? 'bg-folio-600 text-white'
                : 'text-gray-500 hover:bg-gray-200'
            }`}
          >
            {icon} {label}
          </button>
        ))}
      </div>

      {/* Card body */}
      <div className="flex-1 overflow-auto" style={{ maxHeight: '280px' }}>
        {(loadingCache || (isRunning && !results)) && (
          <div className="flex items-center justify-center py-8 gap-2">
            <Loader2 size={18} className="animate-spin text-folio-600" />
            <span className="text-sm text-gray-500">{isRunning ? 'Running…' : 'Loading…'}</span>
          </div>
        )}
        {pollError && (
          <div className="flex items-center gap-2 text-sm text-red-600 p-4">
            <AlertCircle size={14} />
            {pollError}
          </div>
        )}
        {results && displayType === 'table' && (
          <div className="p-3 text-xs">
            <div className="flex gap-3 text-gray-500 mb-2">
              <span><strong>{results.rowCount}</strong> rows</span>
              <span><strong>{results.executionTimeMs}</strong>ms</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="bg-gray-50 border-b">
                    {results.columns.slice(0, 5).map((col) => (
                      <th key={col} className="px-2 py-1 text-left font-semibold text-gray-600 truncate max-w-[120px]">{col}</th>
                    ))}
                    {results.columns.length > 5 && <th className="px-2 py-1 text-gray-400">+{results.columns.length - 5}</th>}
                  </tr>
                </thead>
                <tbody>
                  {results.rows.slice(0, 5).map((row, ri) => (
                    <tr key={ri} className="border-b">
                      {results.columns.slice(0, 5).map((col) => (
                        <td key={col} className="px-2 py-1 truncate max-w-[120px] font-mono">
                          {row[col] === null ? <span className="text-gray-300 italic">null</span> : String(row[col])}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {results.rowCount > 5 && (
              <button onClick={() => onExpand(results, item.name)} className="mt-2 text-folio-600 hover:text-folio-800 text-xs">
                View all {results.rowCount} rows →
              </button>
            )}
          </div>
        )}
        {results && displayType !== 'table' && (
          <ChartPanel
            data={results}
            chartType={displayType as ChartType}
            initialXAxis={chartConfig?.xAxis}
            initialYAxes={chartConfig?.yAxes}
            onConfigChange={handleChartConfigChange}
          />
        )}
        {!loadingCache && !isRunning && !results && !pollError && (
          <div className="flex flex-col items-center justify-center py-8 gap-3 text-gray-400">
            <Play size={24} className="text-gray-300" />
            <p className="text-sm">No data yet</p>
            <button
              onClick={handleRefresh}
              className="text-xs px-3 py-1.5 bg-folio-600 text-white rounded-lg hover:bg-folio-700 transition-colors"
            >
              Run query
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Hidden items panel ───────────────────────────────────────────────────────

function HiddenItems({ items, onRestore }: { items: DashboardItem[]; onRestore: (id: number) => void }) {
  const [open, setOpen] = useState(false);
  if (items.length === 0) return null;

  return (
    <div className="mt-8 border border-dashed border-gray-200 rounded-xl overflow-hidden">
      <button
        onClick={() => setOpen((o) => !o)}
        className="w-full flex items-center justify-between px-5 py-3 text-sm text-gray-500 hover:bg-gray-50 transition-colors"
      >
        <span className="flex items-center gap-2">
          <EyeOff size={14} />
          {items.length} hidden admin item{items.length !== 1 ? 's' : ''}
        </span>
        {open ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
      </button>
      {open && (
        <div className="px-5 py-4 border-t bg-gray-50 space-y-2">
          {items.map((item) => (
            <div key={item.id} className="flex items-center justify-between bg-white rounded-lg border px-4 py-2.5">
              <div className="min-w-0">
                <p className="text-sm font-medium text-gray-700 truncate">{item.name}</p>
                {item.description && <p className="text-xs text-gray-400 truncate">{item.description}</p>}
              </div>
              <button
                onClick={() => onRestore(item.id)}
                className="flex items-center gap-1 text-xs text-folio-600 hover:text-folio-700 ml-4 flex-shrink-0"
              >
                <Eye size={13} /> Restore
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function Dashboard() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const { isAdmin } = useAuth();

  const [items, setItems] = useState<DashboardItem[]>([]);
  const [hiddenItems, setHiddenItems] = useState<DashboardItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [modalData, setModalData] = useState<{ data: ExecuteResponse; title: string } | null>(null);

  // Drag-and-drop
  const [dragIdx, setDragIdx] = useState<number | null>(null);
  const dragIdxRef = useRef<number | null>(null);
  const reorderTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      const res = await fetchDashboard();
      setItems(res.items);
      setHiddenItems(res.hidden);
      setLoadError(null);
    } catch (e: any) {
      setLoadError(e.response?.data?.error || 'Failed to load dashboard');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  // ── Drag handlers ──────────────────────────────────────────────────────────

  const handleDragStart = (i: number) => {
    setDragIdx(i);
    dragIdxRef.current = i;
  };

  const handleDragOver = (e: React.DragEvent, i: number) => {
    e.preventDefault();
    const from = dragIdxRef.current;
    if (from === null || from === i) return;
    setItems((prev) => {
      const next = [...prev];
      const [moved] = next.splice(from, 1);
      next.splice(i, 0, moved);
      return next;
    });
    dragIdxRef.current = i;
    setDragIdx(i);
  };

  const handleDrop = (currentItems: DashboardItem[]) => {
    setDragIdx(null);
    dragIdxRef.current = null;
    if (reorderTimer.current) clearTimeout(reorderTimer.current);
    reorderTimer.current = setTimeout(() => {
      reorderDashboard(currentItems.map((it) => it.id)).catch(() => {});
    }, 400);
  };

  const handleDragEnd = () => {
    setDragIdx(null);
    dragIdxRef.current = null;
  };

  // ── Item actions ───────────────────────────────────────────────────────────

  const handleUnpin = async (item: DashboardItem) => {
    try {
      await togglePin(item.id);
      setItems((prev) => prev.filter((it) => it.id !== item.id));
      queryClient.invalidateQueries({ queryKey: ['pinnedQueries'] });
    } catch { /* ignore */ }
  };

  const handleHide = async (item: DashboardItem) => {
    try {
      await hideDashboardItem(item.id);
      setItems((prev) => prev.filter((it) => it.id !== item.id));
      setHiddenItems((prev) => [{ ...item }, ...prev]);
    } catch { /* ignore */ }
  };

  const handleRestore = async (id: number) => {
    try {
      await showDashboardItem(id);
      const restored = hiddenItems.find((it) => it.id === id);
      setHiddenItems((prev) => prev.filter((it) => it.id !== id));
      if (restored) setItems((prev) => [...prev, restored]);
    } catch { /* ignore */ }
  };

  const handleToggleGlobal = async (item: DashboardItem) => {
    try {
      const updated = await toggleGlobal(item.id);
      setItems((prev) =>
        prev.map((it) =>
          it.id === item.id
            ? { ...it, is_global: updated.is_global, source_type: updated.is_global ? 'global' : 'personal' }
            : it,
        ),
      );
    } catch { /* ignore */ }
  };

  // ── Render ─────────────────────────────────────────────────────────────────

  const globalCount = items.filter((it) => it.is_global).length;

  return (
    <div className="min-h-[calc(100vh-56px)] bg-gray-50">
      {/* Header */}
      <div className="bg-white border-b px-6 py-5">
        <div className="max-w-screen-xl mx-auto">
          <div className="flex items-center justify-between gap-4 flex-wrap">
            <div className="flex items-center gap-3">
              <LayoutDashboard size={24} className="text-folio-600" />
              <div>
                <h1 className="text-xl font-bold">Dashboard</h1>
                <p className="text-sm text-gray-500">
                  Pinned queries run automatically.{' '}
                  {isAdmin
                    ? <span className="text-amber-600">Drag to reorder · <Globe size={11} className="inline" /> pushes a card to all users.</span>
                    : 'Drag to reorder · hide admin items with the eye icon.'}
                </p>
              </div>
            </div>
            {items.length > 0 && (
              <div className="flex items-center gap-2 text-xs text-gray-400">
                <span>{items.length} item{items.length !== 1 ? 's' : ''}</span>
                {globalCount > 0 && (
                  <span className="flex items-center gap-1 bg-amber-50 text-amber-600 border border-amber-200 px-2 py-0.5 rounded">
                    <Globe size={10} /> {globalCount} global
                  </span>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Content */}
      <div className="max-w-screen-xl mx-auto p-6">
        {loading && (
          <div className="flex items-center justify-center py-24 gap-3">
            <Loader2 size={24} className="animate-spin text-folio-600" />
            <span className="text-gray-500">Loading dashboard…</span>
          </div>
        )}

        {loadError && (
          <div className="text-center py-16 text-red-500">
            Failed to load dashboard: {loadError}
          </div>
        )}

        {/* Cards */}
        {!loading && items.length > 0 && (
          <>
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              {items.map((item, i) => (
                <DashboardCard
                  key={item.id}
                  item={item}
                  isAdmin={isAdmin}
                  isDragging={dragIdx === i}
                  isDragOver={dragIdxRef.current !== i && dragIdx !== null && dragIdx === i}
                  onDragStart={() => handleDragStart(i)}
                  onDragOver={(e) => handleDragOver(e, i)}
                  onDrop={() => handleDrop(items)}
                  onDragEnd={handleDragEnd}
                  onUnpin={() => handleUnpin(item)}
                  onHide={() => handleHide(item)}
                  onToggleGlobal={() => handleToggleGlobal(item)}
                  onExpand={(data, title) => setModalData({ data, title })}
                />
              ))}
            </div>
            {items.length > 1 && (
              <p className="mt-4 text-center text-xs text-gray-400 flex items-center justify-center gap-1">
                <GripVertical size={11} /> Drag cards to reorder — order is saved per user
              </p>
            )}
          </>
        )}

        {/* Empty state */}
        {!loading && !loadError && items.length === 0 && (
          <div className="text-center py-20">
            <LayoutDashboard size={40} className="mx-auto text-gray-300 mb-4" />
            <h2 className="text-lg font-semibold text-gray-600 mb-2">Dashboard is empty</h2>
            <p className="text-sm text-gray-400 mb-6 max-w-md mx-auto">
              Pin saved queries to see live results here at a glance. Use the dashboard icon when
              saving from Query History, or pin from the Saved Queries page.
            </p>
            <div className="flex justify-center gap-3 flex-wrap">
              <button
                onClick={() => navigate('/builder')}
                className="flex items-center gap-2 px-4 py-2 bg-folio-600 text-white rounded-lg hover:bg-folio-700 text-sm"
              >
                <Wrench size={16} /> Query Builder
              </button>
              <button
                onClick={() => navigate('/ask')}
                className="flex items-center gap-2 px-4 py-2 border border-folio-300 text-folio-700 rounded-lg hover:bg-folio-50 text-sm"
              >
                <MessageSquare size={16} /> Ask AI
              </button>
              <button
                onClick={() => navigate('/reports')}
                className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 text-sm"
              >
                <FileBarChart size={16} /> Reports
              </button>
            </div>
          </div>
        )}

        {/* Hidden items restore panel */}
        <HiddenItems items={hiddenItems} onRestore={handleRestore} />
      </div>

      {/* Expanded results modal */}
      {modalData && (
        <ResultsModal data={modalData.data} onClose={() => setModalData(null)} title={modalData.title} />
      )}
    </div>
  );
}
