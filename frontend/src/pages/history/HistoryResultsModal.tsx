import { useState, useCallback } from 'react';
import {
  X, Download, Bookmark, LayoutDashboard, Clock, User,
  Loader2, Code2, ChevronDown, ChevronUp, Pencil,
} from 'lucide-react';
import SourceBadge from '../../components/SourceBadge';
import { useEscapeKey } from '../../hooks/useEscapeKey';
import { useCopyToClipboard } from '../../hooks/useCopyToClipboard';
import { useInlineRename } from '../../hooks/useInlineRename';
import { downloadExportCsv } from '../../api/client';
import { downloadCsv } from '../../utils/csv';
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
}

export default function HistoryResultsModal({
  item, job, loading, onClose, onRename, onSave,
}: Props) {
  const [sqlOpen, setSqlOpen] = useState(false);
  const hasFilePreview = !!(job?.outputMode === 'file' && (job.columns?.length || 0) > 0 && (job.rows?.length || 0) > 0);

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

  const handleCsvDownload = () => {
    if (job?.outputMode === 'file' || job?.downloadUrl) {
      void downloadExportCsv(item.jobId);
      return;
    }
    if (job?.columns && job?.rows) {
      downloadCsv(job.columns, job.rows, item.name || 'query');
    }
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
                onClick={handleCsvDownload}
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

        {/* ── Results body ── */}
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center h-48 gap-3 text-gray-400">
              <Loader2 size={20} className="animate-spin text-folio-600" />
              <span className="text-sm">Loading results…</span>
            </div>
          ) : hasFilePreview ? (
            <>
              <div className="px-4 py-3 border-b bg-blue-50 text-xs text-blue-800 flex items-center justify-between gap-3">
                <span>Preview shown below. Download CSV for the full exported result set.</span>
                <button
                  onClick={handleCsvDownload}
                  className="inline-flex items-center gap-1 px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 transition-colors"
                >
                  <Download size={12} /> Download full CSV
                </button>
              </div>
              <table className="w-full text-xs border-separate border-spacing-0">
                <thead className="bg-gray-50 sticky top-0 z-10">
                  <tr>
                    {job.columns!.map((col) => (
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
                  {job.rows!.map((row, i) => (
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
            </>
          ) : (job?.outputMode === 'file' || job?.downloadUrl) ? (
            <div className="flex h-48 items-center justify-center">
              <div className="text-center">
                <div className="text-sm text-gray-600 mb-3">This result is stored as a CSV export file. Download to view data.</div>
                <button
                  onClick={handleCsvDownload}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-folio-600 text-white rounded hover:bg-folio-700 transition-colors"
                >
                  <Download size={14} /> Download CSV
                </button>
              </div>
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
