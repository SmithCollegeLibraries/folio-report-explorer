import { useState, useCallback, useRef } from 'react';
import {
  X, Download, Bookmark, LayoutDashboard, Clock, User,
  Loader2, Code2, ChevronDown, ChevronUp, Pencil, Lightbulb, Play, Sparkles,
  Copy as CopyIcon,
} from 'lucide-react';
import SourceBadge from '../../components/SourceBadge';
import { useEscapeKey } from '../../hooks/useEscapeKey';
import { useCopyToClipboard } from '../../hooks/useCopyToClipboard';
import { useInlineRename } from '../../hooks/useInlineRename';
import HistoryResultsContent from './HistoryResultsContent';
import type { HistoryResultsContentHandle } from './HistoryResultsContent';
import { fmtDate, fmtTime } from '../../utils/format';
import type { HistoryItem, JobStatusResponse } from '../../types';

interface Props {
  item: HistoryItem;
  job: JobStatusResponse | null;
  loading: boolean;
  onClose: () => void;
  /** Called after a successful rename with the new name (empty string = cleared). */
  onRename: (newName: string) => void;
  /** Called when the user clicks Save or Dashboard. */
  onSave: (initialPinned: boolean) => void;
  suggestions: string[];
  suggestionSource?: 'gemini' | 'heuristic' | null;
  suggestionWarnings?: string[];
  suggestionsLoading: boolean;
  suggestionsError: string | null;
  onGenerateSuggestions: () => void;
  onRunSuggestion: (prompt: string) => void;
  onAskFollowUp: (jobId: string) => void;
}

export default function HistoryResultsModal({
  item, job, loading, onClose, onRename, onSave,
  suggestions, suggestionSource, suggestionWarnings,
  suggestionsLoading, suggestionsError, onGenerateSuggestions, onRunSuggestion,
  onAskFollowUp,
}: Props) {
  const [sqlOpen, setSqlOpen] = useState(false);
  const resultsRef = useRef<HistoryResultsContentHandle>(null);
  const isMarcCombinationFinder = item.name?.startsWith('MARC Field Combination Finder')
    || (job?.sql ?? item.sql).includes('"Condition 1 result"');

  const { copiedId, copy } = useCopyToClipboard();

  const rename = useInlineRename({
    onCommit: (_jobId, newName) => onRename(newName),
    onError: () => { /* errors are non-fatal in the modal */ },
  });

  // Close on Escape unless the rename input is open
  useEscapeKey(useCallback(() => { if (!rename.renamingId) onClose(); }, [rename.renamingId, onClose]));

  const handleCopySql = () => {
    const sql = job?.sql || item.sql;
    if (sql) copy('sql', sql);
  };

  const handleCopyOriginalQuery = () => {
    if (item.name) copy('original-query', item.name);
  };

  return (
    <div
      className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-xl shadow-2xl flex flex-col w-full max-w-6xl max-h-[90vh] overflow-hidden">

        {/* ── Header ── */}
        <div className="flex items-start justify-between px-6 py-4 border-b bg-gray-50 flex-shrink-0">
          <div className="flex-1 min-w-0 pr-4">
            <div className="flex items-center gap-2 flex-wrap">
              <SourceBadge source={item.source} />
              {rename.renamingId === item.jobId ? (
                <form
                  onSubmit={(e) => { e.preventDefault(); rename.commit(item.jobId, item.name); }}
                  className="flex items-center gap-1.5 flex-1 min-w-0"
                >
                  <input
                    autoFocus
                    value={rename.renameValue}
                    onChange={(e) => rename.setRenameValue(e.target.value)}
                    onBlur={() => rename.commit(item.jobId, item.name)}
                    onKeyDown={(e) => {
                      if (e.key === 'Escape') { rename.cancel(); }
                    }}
                    disabled={rename.renameSaving}
                    placeholder="Query name…"
                    className="flex-1 px-2 py-0.5 text-sm font-semibold border rounded border-folio-400 focus:ring-1 focus:ring-folio-300 outline-none min-w-0"
                  />
                  {rename.renameSaving && (
                    <Loader2 size={13} className="animate-spin text-folio-500 flex-shrink-0" />
                  )}
                </form>
              ) : (
                <button
                  onClick={() => rename.start(item.jobId, item.name)}
                  className="flex items-center gap-1.5 group/name min-w-0"
                  title="Click to rename"
                >
                  <h2 className="text-base font-semibold text-gray-800 truncate">
                    {item.name ?? (
                      <span className="italic text-gray-400 font-normal">Unnamed query</span>
                    )}
                  </h2>
                  <Pencil
                    size={12}
                    className="text-gray-300 group-hover/name:text-folio-500 flex-shrink-0 transition-colors"
                  />
                </button>
              )}
            </div>
            <div className="flex items-center gap-4 mt-1.5 text-xs text-gray-400 flex-wrap">
              {(item.completedAt ?? item.createdAt) && (
                <span className="flex items-center gap-1">
                  <Clock size={11} />{fmtDate(item.completedAt ?? item.createdAt!)}
                </span>
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
              onClick={() => onAskFollowUp(item.jobId)}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm border border-folio-200 text-folio-700 rounded hover:bg-folio-50 transition-colors"
            >
              <Sparkles size={14} /> Ask follow-up
            </button>
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
                onClick={() => resultsRef.current?.download()}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-folio-600 text-white rounded hover:bg-folio-700 transition-colors"
              >
                <Download size={14} /> Download CSV
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

        {item.name && (
          <div className="border-b px-6 py-3 bg-white flex-shrink-0">
            <div className="flex items-center justify-between gap-3 mb-2">
              <div className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                Original query
              </div>
              <button
                onClick={handleCopyOriginalQuery}
                className="inline-flex items-center gap-1 px-2 py-1 rounded border border-gray-200 text-xs text-gray-500 hover:text-folio-700 hover:bg-folio-50 transition-colors"
              >
                {copiedId === 'original-query'
                  ? <><span className="text-green-600">Copied</span></>
                  : <><CopyIcon size={12} /> Copy original query</>}
              </button>
            </div>
            <div className="max-h-28 overflow-auto rounded border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700 whitespace-pre-wrap break-words leading-relaxed">
              {item.name}
            </div>
          </div>
        )}

        {item.reviewAdvisory && (
          <aside
            role="note"
            className={`border-b border-l-4 px-6 py-3 text-sm flex-shrink-0 ${
              item.reviewAdvisory.state === 'superseded'
                ? 'border-b-sky-200 border-l-sky-500 bg-sky-50 text-sky-950'
                : 'border-b-amber-200 border-l-amber-500 bg-amber-50 text-amber-950'
            }`}
          >
            <div className="font-semibold">
              {item.reviewAdvisory.state === 'superseded' ? 'Updated report available' : 'Report limitation noted'}
            </div>
            <p className="mt-0.5 leading-5">{item.reviewAdvisory.message}</p>
          </aside>
        )}

        {isMarcCombinationFinder && job?.truncated && (
          <aside role="alert" className="border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-900">
            This report reached its 100,000-row cap.
          </aside>
        )}
        {isMarcCombinationFinder && (job?.identifierSkippedCount ?? 0) > 0 && (
          <aside role="alert" className="border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-900">
            {job?.identifierSkippedCount} identifiers could not be exported because they were not a valid FOLIO UUID.
          </aside>
        )}

        {/* ── SQL collapsible ── */}
        <div className="border-b flex-shrink-0">
          <button
            onClick={() => setSqlOpen((o) => !o)}
            className="w-full flex items-center justify-between px-6 py-2.5 text-xs text-gray-500 hover:bg-gray-50 transition-colors"
          >
            <span className="flex items-center gap-1.5 font-medium">
              <Code2 size={13} /> SQL
            </span>
            <div className="flex items-center gap-2">
              <span
                role="button"
                onClick={(e) => { e.stopPropagation(); handleCopySql(); }}
                className="flex items-center gap-1 px-2 py-0.5 rounded border border-gray-200 hover:bg-white transition-colors cursor-pointer"
              >
                {copiedId === 'sql'
                  ? <span className="text-green-600">✓ Copied</span>
                  : 'Copy'}
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

        {/* ── Related suggestions ── */}
        <div className="border-b px-6 py-3 bg-folio-50/40">
          <div className="flex items-center justify-between gap-3">
            <div className="text-sm font-semibold text-folio-800 flex items-center gap-2">
              <Lightbulb size={14} className="text-amber-500" />
              Related Query Suggestions
            </div>
            <button
              onClick={onGenerateSuggestions}
              disabled={suggestionsLoading}
              className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded border border-folio-200 text-folio-700 bg-white hover:bg-folio-100 disabled:opacity-50 text-xs"
            >
              {suggestionsLoading ? <Loader2 size={12} className="animate-spin" /> : <Lightbulb size={12} />}
              {suggestionsLoading ? 'Generating…' : suggestions.length > 0 ? 'Refresh Suggestions' : 'Generate Suggestions'}
            </button>
          </div>

          {suggestionSource && (
            <div className="mt-1 text-xs text-gray-500">
              Source: {suggestionSource === 'heuristic' ? 'Heuristic fallback' : 'Gemini'}
            </div>
          )}

          {suggestionsError && (
            <div className="mt-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded px-2 py-1.5">
              {suggestionsError}
            </div>
          )}

          {suggestionWarnings && suggestionWarnings.length > 0 && (
            <div className="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">
              {suggestionWarnings.join(' | ')}
            </div>
          )}

          {suggestions.length > 0 && (
            <div className="mt-2 space-y-2">
              {suggestions.map((suggestion, idx) => (
                <div key={`${suggestion}-${idx}`} className="flex items-center gap-2">
                  <div className="flex-1 text-xs text-gray-700 bg-white border border-gray-200 rounded px-2.5 py-2">
                    {suggestion}
                  </div>
                  <button
                    onClick={() => onRunSuggestion(suggestion)}
                    className="inline-flex items-center gap-1 px-2.5 py-2 rounded bg-folio-600 text-white hover:bg-folio-700 text-xs"
                    title="Run in Ask AI"
                  >
                    <Play size={11} /> Run in Ask
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* ── Results body ── */}
        <div className="flex-1 overflow-auto">
          <HistoryResultsContent ref={resultsRef} item={item} job={job} loading={loading} />
        </div>
      </div>
    </div>
  );
}
