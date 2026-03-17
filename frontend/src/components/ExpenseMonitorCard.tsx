import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  fetchExpenseMonitors,
  fetchExpenseMonitorCodes,
  saveExpenseMonitors,
  refreshExpenseMonitor,
} from '../api/client';
import { useJobPolling } from '../hooks/useJobPolling';
import type { ExpenseMonitorCode, ExecuteResponse } from '../types';
import {
  DollarSign, Settings, RefreshCw, Loader2, AlertCircle,
  X, Check, ChevronRight, ChevronLeft, Info,
} from 'lucide-react';

// ── Row type for the results table ────────────────────────────────

interface BudgetRow {
  code: string;
  name: string;
  allocation: number | null;
  totalPayments: number;
  totalEncumbrances: number;
  totalSpent: number;
  remaining: number | null;
}

/** Fraction of allocation consumed (payments + encumbrances). Returns null if no allocation. */
function spentFraction(row: BudgetRow): number | null {
  if (!row.allocation || row.allocation === 0) return null;
  return row.totalSpent / row.allocation;
}

/** Tailwind colour classes driven by how much of the budget has been used. */
function statusColors(fraction: number | null): { bar: string; text: string; bg: string } {
  if (fraction === null) return { bar: 'bg-gray-300', text: 'text-gray-500', bg: 'bg-gray-50' };
  if (fraction >= 0.9) return { bar: 'bg-red-500',   text: 'text-red-600',   bg: 'bg-red-50' };
  if (fraction >= 0.5) return { bar: 'bg-amber-400', text: 'text-amber-700', bg: 'bg-amber-50' };
  return { bar: 'bg-green-500', text: 'text-green-700', bg: 'bg-green-50' };
}

/** Return the fiscal year that contains the given date (FY = year ending Jun 30). */
function getCurrentFiscalYear(): number {
  const d = new Date();
  return d.getMonth() >= 6 ? d.getFullYear() + 1 : d.getFullYear();
}

/** Jul 1 (fy-1) → Jun 30 (fy) display range for a fiscal year. */
function fyDateRange(fy: number): { start: string; end: string } {
  return { start: `Jul 1, ${fy - 1}`, end: `Jun 30, ${fy}` };
}

function fmt(value: number | null): string {
  if (value === null || value === undefined) return '—';
  return new Intl.NumberFormat('en-US', {
    style: 'currency', currency: 'USD', maximumFractionDigits: 0,
  }).format(value);
}

// ── Parse raw result rows into typed BudgetRow objects ────────────

function parseResults(results: ExecuteResponse): BudgetRow[] {
  return results.rows.map((r) => ({
    code:               String(r['Expense Class Code'] ?? r['code'] ?? ''),
    name:               String(r['Expense Class Name'] ?? r['name'] ?? ''),
    allocation:         r['Allocation'] !== null && r['Allocation'] !== undefined ? Number(r['Allocation']) : null,
    totalPayments:      Number(r['Total Payments'] ?? 0),
    totalEncumbrances:  Number(r['Total Encumbrances'] ?? 0),
    totalSpent:         Number(r['Total Spent'] ?? 0),
    remaining:          r['Remaining'] !== null && r['Remaining'] !== undefined ? Number(r['Remaining']) : null,
  }));
}

// ── Code-selector modal ────────────────────────────────────────────

interface CodeSelectorProps {
  available: ExpenseMonitorCode[];
  selected: string[];
  loading: boolean;
  onSave: (codes: string[]) => void;
  onClose: () => void;
}

function CodeSelector({ available, selected, loading, onSave, onClose }: CodeSelectorProps) {
  const [draft, setDraft] = useState<Set<string>>(new Set(selected));

  const toggle = (code: string) => {
    setDraft((prev) => {
      const next = new Set(prev);
      if (next.has(code)) {
        next.delete(code);
      } else {
        next.add(code);
      }
      return next;
    });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b">
          <h2 className="font-semibold text-gray-800">Select Expense Classes to Monitor</h2>
          <button onClick={onClose} className="p-1 text-gray-400 hover:text-gray-600 rounded">
            <X size={18} />
          </button>
        </div>

        {/* List */}
        <div className="overflow-y-auto max-h-80 px-2 py-2">
          {loading ? (
            <div className="flex items-center justify-center py-8 gap-2 text-gray-400">
              <Loader2 size={18} className="animate-spin" />
              <span className="text-sm">Loading expense classes…</span>
            </div>
          ) : available.length === 0 ? (
            <p className="text-sm text-gray-400 text-center py-6">No SC expense classes found.</p>
          ) : (
            available.map(({ code, name }) => (
              <label
                key={code}
                className="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50 cursor-pointer"
              >
                <div
                  className={`w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors ${
                    draft.has(code)
                      ? 'bg-folio-600 border-folio-600'
                      : 'border-gray-300'
                  }`}
                >
                  {draft.has(code) && <Check size={12} className="text-white" />}
                </div>
                <span className="text-sm font-mono font-medium text-gray-700 w-16 flex-shrink-0">{code}</span>
                <span className="text-sm text-gray-500 truncate">{name}</span>
                <input
                  type="checkbox"
                  className="sr-only"
                  checked={draft.has(code)}
                  onChange={() => toggle(code)}
                />
              </label>
            ))
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between px-5 py-3 border-t bg-gray-50 rounded-b-2xl">
          <span className="text-xs text-gray-400 italic">
            {draft.size} selected
          </span>
          <div className="flex gap-2">
            <button
              onClick={onClose}
              className="px-3 py-1.5 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-100 transition-colors"
            >
              Cancel
            </button>
            <button
              onClick={() => onSave(Array.from(draft))}
              className="px-3 py-1.5 text-sm bg-folio-600 text-white rounded-lg hover:bg-folio-700 transition-colors"
            >
              Save
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ── Main card component ────────────────────────────────────────────

interface Props {
  /** Initial fiscal year (defaults to current). Managed internally after mount. */
  fiscalYear?: number;
}

export default function ExpenseMonitorCard({ fiscalYear }: Props) {
  const navigate = useNavigate();

  const [activeYear, setActiveYear] = useState<number>(
    () => fiscalYear ?? getCurrentFiscalYear()
  );
  const { start: fyStart, end: fyEnd } = fyDateRange(activeYear);
  // Suppress re-run on the initial mount (only react to genuine year changes)
  const yearInitialized = useRef(false);

  // Persisted setup dismissal — removed: card is now always mounted when the user adds
  // the Budget Monitor widget from the gallery, so no dismiss flow is needed.
  const [monitoredCodes, setMonitoredCodes] = useState<string[]>([]);
  const [loadingCodes, setLoadingCodes] = useState(true);
  const [availableCodes, setAvailableCodes] = useState<ExpenseMonitorCode[]>([]);
  const [loadingAvailable, setLoadingAvailable] = useState(false);
  const [selectorOpen, setSelectorOpen] = useState(false);
  const [results, setResults] = useState<BudgetRow[] | null>(null);
  const [lastRefreshed, setLastRefreshed] = useState<string | null>(null);

  // Async job polling
  const [pollingJobId, setPollingJobId] = useState<string | null>(null);
  const { results: polledResults, isRunning, error: pollError, reset: resetPoll } = useJobPolling(pollingJobId);

  // Load the user's monitored codes on mount
  const loadMonitoredCodes = useCallback(async () => {
    try {
      setLoadingCodes(true);
      const codes = await fetchExpenseMonitors();
      setMonitoredCodes(codes);
    } catch {
      // Silently degrade — the setup prompt will show
    } finally {
      setLoadingCodes(false);
    }
  }, []);

  useEffect(() => { loadMonitoredCodes(); }, [loadMonitoredCodes]);

  // When polling finishes, update results
  useEffect(() => {
    if (polledResults) {
      setResults(parseResults(polledResults));
      setLastRefreshed(new Date().toLocaleTimeString());
    }
  }, [polledResults]);

  // Auto-run once we have codes (on mount / after codes change)
  useEffect(() => {
    if (monitoredCodes.length > 0 && !isRunning && results === null) {
      handleRefresh();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [monitoredCodes]);

  // Re-run when the user changes the fiscal year
  useEffect(() => {
    if (!yearInitialized.current) {
      yearInitialized.current = true;
      return;
    }
    if (monitoredCodes.length > 0) {
      setResults(null);
      resetPoll();
      setPollingJobId(null);
      refreshExpenseMonitor(activeYear)
        .then((resp) => setPollingJobId(resp.jobId))
        .catch(() => { /* pollError surfaces failure */ });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeYear]);

  const handleRefresh = async () => {
    try {
      resetPoll();
      const resp = await refreshExpenseMonitor(activeYear);
      setPollingJobId(resp.jobId);
    } catch {
      // pollError will surface the failure
    }
  };

  const handleYearChange = (delta: number) => {
    setActiveYear((prev) => prev + delta);
  };

  const openSelector = async () => {
    setSelectorOpen(true);
    if (availableCodes.length === 0) {
      setLoadingAvailable(true);
      try {
        const codes = await fetchExpenseMonitorCodes();
        setAvailableCodes(codes);
      } catch { /**/ } finally {
        setLoadingAvailable(false);
      }
    }
  };

  const handleSaveCodes = async (codes: string[]) => {
    setSelectorOpen(false);
    try {
      await saveExpenseMonitors(codes);
      setMonitoredCodes(codes);
      setResults(null);
      resetPoll();
      setPollingJobId(null);
      if (codes.length > 0) {
        // Trigger a fresh run for the new selection
        const resp = await refreshExpenseMonitor(activeYear);
        setPollingJobId(resp.jobId);
      }
    } catch { /**/ }
  };

  // ── Render: loading phase ──────────────────────────────────────
  if (loadingCodes) {
    return (
      <div className="border rounded-xl bg-white shadow-sm p-6 flex items-center gap-3 text-gray-400">
        <Loader2 size={18} className="animate-spin text-folio-500" />
        <span className="text-sm">Loading budget monitor…</span>
      </div>
    );
  }

  // ── Render: full monitor card ──────────────────────────────────
  const budgetRows = results ?? [];
  const totalAlloc     = budgetRows.reduce((s, r) => s + (r.allocation ?? 0), 0);
  const totalSpent     = budgetRows.reduce((s, r) => s + r.totalSpent, 0);
  const totalRemaining = budgetRows.reduce((s, r) => s + (r.remaining ?? (r.allocation ? r.allocation - r.totalSpent : 0)), 0);

  return (
    <>
      <div className="border rounded-xl bg-white shadow-sm flex flex-col col-span-full">
        {/* Header */}
        <div className="px-4 py-3 border-b">
          <div className="flex items-center gap-3">
            <div className="p-1.5 bg-folio-50 rounded-lg flex-shrink-0">
              <DollarSign size={16} className="text-folio-600" />
            </div>
            <div className="flex-1 min-w-0">
              <h3 className="font-semibold text-sm text-gray-800">Budget Monitor</h3>
              <p className="text-xs text-gray-400">
                {fyStart} – {fyEnd}
                {lastRefreshed && ` · Updated ${lastRefreshed}`}
              </p>
            </div>
            {/* Fiscal year navigation */}
            <div className="flex items-center gap-0.5 border border-gray-200 rounded-lg overflow-hidden flex-shrink-0">
              <button
                onClick={() => handleYearChange(-1)}
                title="Previous fiscal year"
                className="px-1.5 py-1 text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors"
              >
                <ChevronLeft size={14} />
              </button>
              <span className="px-2 py-1 text-xs font-semibold text-gray-700 bg-white border-x border-gray-200 min-w-[4rem] text-center">
                FY {activeYear}
              </span>
              <button
                onClick={() => handleYearChange(1)}
                title="Next fiscal year"
                className="px-1.5 py-1 text-gray-400 hover:text-folio-600 hover:bg-folio-50 transition-colors"
              >
                <ChevronRight size={14} />
              </button>
            </div>
            <div className="flex items-center gap-1 flex-shrink-0">
              {isRunning ? (
                <Loader2 size={14} className="animate-spin text-folio-600 mx-1" />
              ) : (
                <button
                  onClick={handleRefresh}
                  title="Refresh budget data"
                  className="p-1.5 text-gray-400 hover:text-folio-600 rounded transition-colors"
                >
                  <RefreshCw size={14} />
                </button>
              )}
              <button
                onClick={openSelector}
                title="Configure monitored expense classes"
                className="p-1.5 text-gray-400 hover:text-folio-600 rounded transition-colors"
              >
                <Settings size={14} />
              </button>
              <button
                onClick={() => navigate('/reports/36')}
                title="Open Budget Year Expense Class Report"
                className="flex items-center gap-1 px-2 py-1 text-xs text-folio-600 hover:text-folio-700 border border-folio-200 rounded-lg hover:bg-folio-50 transition-colors"
              >
                Full Report <ChevronRight size={12} />
              </button>
            </div>
          </div>
          {/* Info strip: tracked codes */}
          {monitoredCodes.length > 0 && (
            <div className="flex items-center gap-2 mt-2 pt-2 border-t border-gray-100">
              <Info size={12} className="text-gray-300 flex-shrink-0" />
              <span className="text-xs text-gray-400">Tracking:</span>
              <div className="flex flex-wrap gap-1">
                {monitoredCodes.map((code) => (
                  <span
                    key={code}
                    className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-mono bg-folio-50 text-folio-700 border border-folio-100"
                  >
                    {code}
                  </span>
                ))}
              </div>
              <span className="text-xs text-gray-300 ml-auto whitespace-nowrap">
                SC Acquisition Unit
              </span>
            </div>
          )}
        </div>

        {/* Summary bar */}
        {results !== null && totalAlloc > 0 && (
          <div className="px-4 py-3 bg-gray-50 border-b flex items-center gap-6 text-xs">
            <div>
              <span className="text-gray-400">Total Allocation</span>
              <p className="font-semibold text-gray-800 text-sm">{fmt(totalAlloc)}</p>
            </div>
            <div>
              <span className="text-gray-400">Total Spent</span>
              <p className="font-semibold text-gray-800 text-sm">{fmt(totalSpent)}</p>
            </div>
            <div className="flex-1">
              <div className="flex justify-between text-gray-400 mb-1">
                <span>Remaining</span>
                <span>{Math.round((totalSpent / totalAlloc) * 100)}% used</span>
              </div>
              <div className="w-full h-1.5 bg-gray-200 rounded-full overflow-hidden">
                <div
                  className={`h-full rounded-full transition-all ${statusColors(totalAlloc > 0 ? totalSpent / totalAlloc : null).bar}`}
                  style={{ width: `${Math.min(100, Math.round((totalSpent / totalAlloc) * 100))}%` }}
                />
              </div>
            </div>
            <div>
              <span className="text-gray-400">Remaining</span>
              <p className={`font-semibold text-sm ${totalRemaining < 0 ? 'text-red-600' : 'text-green-700'}`}>
                {fmt(totalRemaining)}
              </p>
            </div>
          </div>
        )}

        {/* Body */}
        <div className="overflow-x-auto">
          {(isRunning && !results) ? (
            <div className="flex items-center justify-center py-10 gap-2 text-gray-400">
              <Loader2 size={18} className="animate-spin text-folio-600" />
              <span className="text-sm">Loading budget data…</span>
            </div>
          ) : pollError ? (
            <div className="flex items-center gap-2 text-sm text-red-600 p-4">
              <AlertCircle size={14} />
              Failed to load: {pollError}
            </div>
          ) : budgetRows.length === 0 && !isRunning ? (
            monitoredCodes.length === 0 ? (
              // ── Inline nudge when no expense classes have been selected ──
              <div className="flex items-center gap-3 px-4 py-6 text-sm text-gray-400">
                <Settings size={16} className="flex-shrink-0 text-gray-300" />
                <span>
                  Click <strong className="text-gray-600">⚙</strong> to choose expense classes to monitor, or{' '}
                  <button
                    onClick={() => navigate('/reports/36')}
                    className="text-folio-600 hover:underline"
                  >
                    view the full report
                  </button>
                  .
                </span>
              </div>
            ) : (
            <div className="flex flex-col items-center justify-center py-10 gap-3 text-gray-400">
              <p className="text-sm">No data yet</p>
              <button
                onClick={handleRefresh}
                className="text-xs px-3 py-1.5 bg-folio-600 text-white rounded-lg hover:bg-folio-700 transition-colors"
              >
                Load Data
              </button>
            </div>
            )
          ) : (
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-gray-50 border-b text-left">
                  <th className="px-4 py-2 font-semibold text-gray-500 w-20">Code</th>
                  <th className="px-4 py-2 font-semibold text-gray-500">Name</th>
                  <th className="px-4 py-2 font-semibold text-gray-500 text-right">Allocation</th>
                  <th className="px-4 py-2 font-semibold text-gray-500 text-right">Payments</th>
                  <th className="px-4 py-2 font-semibold text-gray-500 text-right">Encumbrances</th>
                  <th className="px-4 py-2 font-semibold text-gray-500 text-right">Total Spent</th>
                  <th className="px-4 py-2 font-semibold text-gray-500 text-right">Remaining</th>
                  <th className="px-4 py-2 font-semibold text-gray-500 w-28">Usage</th>
                </tr>
              </thead>
              <tbody>
                {budgetRows.map((row) => {
                  const frac   = spentFraction(row);
                  const colors = statusColors(frac);
                  return (
                    <tr key={row.code} className={`border-b last:border-0 hover:bg-gray-50 ${colors.bg}`}>
                      <td className="px-4 py-2 font-mono font-semibold text-gray-700">{row.code}</td>
                      <td className="px-4 py-2 text-gray-600 max-w-[180px] truncate" title={row.name}>{row.name || '—'}</td>
                      <td className="px-4 py-2 text-right text-gray-700">{fmt(row.allocation)}</td>
                      <td className="px-4 py-2 text-right text-gray-600">{fmt(row.totalPayments)}</td>
                      <td className="px-4 py-2 text-right text-gray-600">{fmt(row.totalEncumbrances)}</td>
                      <td className="px-4 py-2 text-right font-medium text-gray-700">{fmt(row.totalSpent)}</td>
                      <td className={`px-4 py-2 text-right font-semibold ${colors.text}`}>
                        {fmt(row.remaining)}
                      </td>
                      <td className="px-4 py-2">
                        {frac !== null ? (
                          <div className="flex items-center gap-1.5">
                            <div className="flex-1 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                              <div
                                className={`h-full rounded-full ${colors.bar}`}
                                style={{ width: `${Math.min(100, Math.round(frac * 100))}%` }}
                              />
                            </div>
                            <span className={`w-8 text-right ${colors.text}`}>
                              {Math.round(frac * 100)}%
                            </span>
                          </div>
                        ) : (
                          <span className="text-gray-300">—</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {selectorOpen && (
        <CodeSelector
          available={availableCodes}
          selected={monitoredCodes}
          loading={loadingAvailable}
          onSave={handleSaveCodes}
          onClose={() => setSelectorOpen(false)}
        />
      )}
    </>
  );
}
