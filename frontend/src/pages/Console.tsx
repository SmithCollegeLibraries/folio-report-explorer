import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Terminal, Play, Square, Loader2, Save, Copy, Check, Info } from 'lucide-react';
import { submitQuery, downloadExportCsv } from '../api/client';
import { useJobPolling } from '../hooks/useJobPolling';
import SqlPreview from '../components/SqlPreview';
import ResultsTable from '../components/ResultsTable';
import SaveQueryDialog from '../components/SaveQueryDialog';
import type { HistoryItem } from '../types';

const DEFAULT_SQL = '-- Enter your SQL here\nSELECT 1';

export default function Console() {
  const [sql, setSql] = useState(DEFAULT_SQL);
  const [dataSource, setDataSource] = useState<'folio' | 'local'>('folio');
  const [activeJobId, setActiveJobId] = useState<string | null>(null);
  const [saveOpen, setSaveOpen] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [outputPref, setOutputPref] = useState<'preview' | 'full'>(
    () => (localStorage.getItem('folio_output_pref') as 'preview' | 'full') ?? 'preview',
  );

  const {
    results,
    isRunning,
    error: jobError,
    cancel: cancelJob,
    reset: resetJob,
    elapsedSeconds,
  } = useJobPolling(activeJobId);

  const execMut = useMutation({
    mutationFn: ({
      sqlToRun,
      options,
    }: {
      sqlToRun: string;
      options?: { outputMode?: 'table' | 'file' };
    }) => submitQuery(sqlToRun, {}, 'manual', undefined, dataSource, options),
    onSuccess: (data) => {
      if (data.jobId) {
        setActiveJobId(data.jobId);
      }
    },
  });

  const handleRun = () => {
    const trimmed = sql.trim();
    if (!trimmed || trimmed === '--') return;
    resetJob();
    setActiveJobId(null);
    execMut.mutate({ sqlToRun: trimmed, options: { outputMode: outputPref === 'full' ? 'file' : 'table' } });
  };

  const handleExplain = () => {
    const trimmed = sql.trim().replace(/;+\s*$/, '');
    if (!trimmed) return;
    resetJob();
    setActiveJobId(null);
    execMut.mutate({ sqlToRun: `EXPLAIN (FORMAT JSON)\n${trimmed}` });
  };

  const handleCopy = () => {
    navigator.clipboard.writeText(sql).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  };

  const isExecuting = execMut.isPending || isRunning;

  // Construct a synthetic HistoryItem so SaveQueryDialog can save with correct shape
  const historyItemForSave: HistoryItem | null = results
    ? {
        jobId: activeJobId ?? '',
        name: null,
        status: 'completed',
        sql,
        source: 'manual',
        dataSource,
        progressMessage: null,
        rowCount: results.rowCount,
        executionTimeMs: results.executionTimeMs,
        errorMessage: null,
        createdAt: new Date().toISOString(),
        startedAt: null,
        completedAt: new Date().toISOString(),
        runBy: null,
        canDelete: true,
      }
    : null;

  return (
    <div className="flex flex-col h-[calc(100vh-56px)]">
      {/* Editor panel */}
      <div className="p-6 bg-white border-b">
        <div className="max-w-5xl mx-auto">
          <div className="flex items-center gap-2 mb-4">
            <Terminal size={20} className="text-folio-600" />
            <h2 className="text-lg font-semibold">SQL Console</h2>
            <span className="text-xs text-gray-400 ml-1">
              Run raw SQL directly against FOLIO or local data
            </span>
          </div>

          {/* Data source + output preference toggles */}
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 mb-3">
            <div className="flex items-center gap-3">
              <span className="text-xs font-medium text-gray-500">Data source:</span>
              <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs">
                <button
                  onClick={() => setDataSource('folio')}
                  className={`px-3 py-1.5 transition-colors ${
                    dataSource === 'folio'
                      ? 'bg-folio-600 text-white'
                      : 'text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  FOLIO (PostgreSQL)
                </button>
                <button
                  onClick={() => setDataSource('local')}
                  className={`px-3 py-1.5 border-l border-gray-200 transition-colors ${
                    dataSource === 'local'
                      ? 'bg-folio-600 text-white'
                      : 'text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  Local (MySQL)
                </button>
              </div>
            </div>
            <div className="flex items-center gap-3">
              <span className="text-xs font-medium text-gray-500">Results:</span>
              <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs">
                <button
                  onClick={() => { setOutputPref('preview'); localStorage.setItem('folio_output_pref', 'preview'); }}
                  className={`px-3 py-1.5 transition-colors ${
                    outputPref === 'preview'
                      ? 'bg-folio-600 text-white'
                      : 'text-gray-600 hover:bg-gray-50'
                  }`}
                  title="Show up to 100 rows in the browser"
                >
                  Preview (100 rows)
                </button>
                <button
                  onClick={() => { setOutputPref('full'); localStorage.setItem('folio_output_pref', 'full'); }}
                  className={`px-3 py-1.5 border-l border-gray-200 transition-colors ${
                    outputPref === 'full'
                      ? 'bg-folio-600 text-white'
                      : 'text-gray-600 hover:bg-gray-50'
                  }`}
                  title="Export all rows as a downloadable CSV"
                >
                  All results (CSV)
                </button>
              </div>
            </div>
          </div>

          {/* Monaco SQL editor */}
          <SqlPreview sql={sql} onChange={setSql} readOnly={false} height="280px" />

          {/* Action toolbar */}
          <div className="flex items-center gap-2 mt-3">
            {!isExecuting ? (
              <>
                <button
                  onClick={handleRun}
                  disabled={!sql.trim()}
                  className="flex items-center gap-1.5 bg-folio-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-folio-700 disabled:opacity-50 transition-colors"
                >
                  <Play size={14} /> Run
                </button>
                <button
                  onClick={handleExplain}
                  disabled={!sql.trim() || dataSource === 'local'}
                  title={
                    dataSource === 'local'
                      ? 'EXPLAIN is only available for FOLIO (PostgreSQL)'
                      : 'Run EXPLAIN (FORMAT JSON) to view the query plan without executing'
                  }
                  className="flex items-center gap-1.5 border border-gray-300 text-gray-700 text-sm px-4 py-2 rounded-lg hover:bg-gray-50 disabled:opacity-40 transition-colors"
                >
                  <Info size={14} /> Explain
                </button>
              </>
            ) : (
              <button
                onClick={cancelJob}
                className="flex items-center gap-1.5 bg-red-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-red-700 transition-colors"
              >
                <Square size={14} /> Cancel
                {elapsedSeconds > 0 && (
                  <span className="ml-1 opacity-80">{elapsedSeconds}s</span>
                )}
              </button>
            )}

            <button
              onClick={handleCopy}
              className="flex items-center gap-1.5 border border-gray-300 text-gray-600 text-sm px-3 py-2 rounded-lg hover:bg-gray-50 transition-colors"
              title="Copy SQL to clipboard"
            >
              {copied ? (
                <Check size={14} className="text-green-600" />
              ) : (
                <Copy size={14} />
              )}
            </button>

            {isExecuting && (
              <span className="flex items-center gap-1.5 text-sm text-gray-500 ml-1">
                <Loader2 size={14} className="animate-spin" />
                Running{elapsedSeconds > 0 ? ` (${elapsedSeconds}s)` : '…'}
              </span>
            )}

            {results && historyItemForSave && !isExecuting && (
              <button
                onClick={() => setSaveOpen(true)}
                className="flex items-center gap-1.5 border border-gray-300 text-gray-600 text-sm px-3 py-2 rounded-lg hover:bg-gray-50 ml-auto transition-colors"
              >
                <Save size={14} /> Save
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Success toast */}
      {saveSuccess && (
        <div className="max-w-5xl mx-auto mt-3 px-6 w-full">
          <div className="flex items-center gap-2 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm text-green-700">
            <Check size={14} />
            {saveSuccess}
          </div>
        </div>
      )}

      {/* Results area */}
      <div className="flex-1 overflow-y-auto">
        <div className="max-w-5xl mx-auto p-6">
          {/* Error */}
          {(execMut.isError || jobError) && (
            <div className="p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 font-mono whitespace-pre-wrap">
              <strong className="font-sans font-semibold">Error: </strong>
              {jobError ||
                ((execMut.error as any)?.response?.data?.error) ||
                (execMut.error instanceof Error
                  ? execMut.error.message
                  : String(execMut.error))}
            </div>
          )}

          {/* Running — no results yet */}
          {isExecuting && !results && (
            <div className="flex items-center justify-center py-20 text-gray-400">
              <Loader2 size={24} className="animate-spin mr-2" />
              <span className="text-sm">Executing query…</span>
            </div>
          )}

          {/* Results */}
          {results && !isExecuting && (
            <>
              {results.outputMode === 'file' ? (
                <div className="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
                  <Check size={16} />
                  <span>CSV export ready.</span>
                  {activeJobId && (
                    <button
                      onClick={() => downloadExportCsv(activeJobId)}
                      className="ml-auto flex items-center gap-1 text-folio-600 hover:text-folio-800 font-medium"
                    >
                      Download CSV
                    </button>
                  )}
                </div>
              ) : (
                <ResultsTable data={results} />
              )}
            </>
          )}

          {/* Empty state */}
          {!isExecuting && !results && !execMut.isError && !jobError && (
            <div className="text-center py-20 text-gray-400">
              <Terminal size={40} className="mx-auto mb-3 opacity-30" />
              <p className="text-sm">
                Enter SQL above and click <strong>Run</strong>
              </p>
              <p className="text-xs mt-1 opacity-70">
                Use <strong>Explain</strong> to view the query execution plan without running the query
              </p>
            </div>
          )}
        </div>
      </div>

      {/* Save dialog */}
      {saveOpen && historyItemForSave && (
        <SaveQueryDialog
          item={historyItemForSave}
          initialPinned={false}
          onClose={() => setSaveOpen(false)}
          onSaved={(sq) => {
            setSaveOpen(false);
            setSaveSuccess(`Saved as "${sq.name}"`);
            setTimeout(() => setSaveSuccess(null), 4000);
          }}
        />
      )}
    </div>
  );
}
