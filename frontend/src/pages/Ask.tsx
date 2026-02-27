import { useState, useRef, useEffect } from 'react';
import { useMutation } from '@tanstack/react-query';
import { askNl, submitQuery, saveQuery, promoteToReport, submitCorrection } from '../api/client';
import { useJobPolling } from '../hooks/useJobPolling';
import SqlPreview from '../components/SqlPreview';
import ResultsTable from '../components/ResultsTable';
import ResultsModal from '../components/ResultsModal';
import type { NlResponse } from '../types';
import {
  Send, Play, Copy, Sparkles, RotateCcw, Square, Loader2,
  Maximize2, Save, FileBarChart, Check, ThumbsDown, Pencil, X,
  Clock, Code2, Table2,
} from 'lucide-react';

const EXAMPLE_PROMPTS = [
  'Show all items checked out in the last 30 days with their patron names',
  'Count how many items each location has',
  'List all loans that are overdue with borrower name and item title',
  'Show the top 10 most popular material types by checkout count',
  'Find all patrons with more than 5 active loans',
];

export default function Ask() {
  const [prompt, setPrompt] = useState('');
  const [nlResult, setNlResult] = useState<NlResponse | null>(null);
  const [activeJobId, setActiveJobId] = useState<string | null>(null);
  const [history, setHistory] = useState<
    { prompt: string; result: NlResponse }[]
  >([]);

  // Modal state
  const [modalOpen, setModalOpen] = useState(false);

  // Save dialog state
  const [saveOpen, setSaveOpen] = useState(false);
  const [saveName, setSaveName] = useState('');
  const [saveDesc, setSaveDesc] = useState('');
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null);
  const [lastSavedId, setLastSavedId] = useState<number | null>(null);

  // Correction state
  const [correcting, setCorrecting] = useState(false);
  const [correctedSql, setCorrectedSql] = useState('');
  const [correctionNotes, setCorrectionNotes] = useState('');

  // Tab state: results-first view
  const [detailTab, setDetailTab] = useState<'results' | 'details'>('results');

  // History popover state
  const [historyOpen, setHistoryOpen] = useState(false);
  const historyRef = useRef<HTMLDivElement>(null);

  // Close history popover on outside click
  useEffect(() => {
    if (!historyOpen) return;
    const handleClick = (e: MouseEvent) => {
      if (historyRef.current && !historyRef.current.contains(e.target as Node)) {
        setHistoryOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [historyOpen]);

  // --- async job polling ---
  const { job, results, isRunning, error: jobError, cancel: cancelJobFn, reset: resetJob, elapsedSeconds } = useJobPolling(activeJobId);

  const execMut = useMutation({
    mutationFn: (sql: string) => submitQuery(sql, {}, 'nl', prompt.trim() || undefined),
    onSuccess: (data: { jobId: string }) => setActiveJobId(data.jobId),
  });

  const askMut = useMutation({
    mutationFn: (question: string) => askNl(question),
    onSuccess: (data: NlResponse, question: string) => {
      setNlResult(data);
      resetJob();
      setActiveJobId(null);
      setSaveSuccess(null);
      setLastSavedId(null);
      setCorrecting(false);
      setDetailTab('results');
      setHistory((prev) => [{ prompt: question, result: data }, ...prev].slice(0, 20));
      // Auto-run the generated SQL
      if (data.sql) {
        execMut.mutate(data.sql);
      }
    },
  });

  const savedMut = useMutation({
    mutationFn: () =>
      saveQuery({
        name: saveName,
        description: saveDesc,
        queryDefinition: {},
        generatedSql: nlResult?.sql,
        source: 'nl',
        nlPrompt: history[0]?.prompt || prompt,
      }),
    onSuccess: (data) => {
      setSaveOpen(false);
      setSaveName('');
      setSaveDesc('');
      setLastSavedId(data.id);
      setSaveSuccess(`Saved as "${data.name}"`);
      setTimeout(() => setSaveSuccess(null), 4000);
    },
  });

  const promoteMut = useMutation({
    mutationFn: (id: number) => promoteToReport(id),
    onSuccess: (data) => {
      setSaveSuccess(`Promoted to report "${data.name}"`);
      setTimeout(() => setSaveSuccess(null), 4000);
    },
  });

  const correctionMut = useMutation({
    mutationFn: () =>
      submitCorrection({
        prompt: history[0]?.prompt || prompt,
        originalSql: nlResult?.sql || '',
        correctedSql,
        notes: correctionNotes || undefined,
      }),
    onSuccess: (data) => {
      setCorrecting(false);
      setCorrectedSql('');
      setCorrectionNotes('');
      setSaveSuccess(data.message);
      setTimeout(() => setSaveSuccess(null), 5000);
    },
  });

  const handleSubmit = () => {
    const q = prompt.trim();
    if (!q) return;
    askMut.mutate(q);
  };

  const handleCopy = () => {
    if (nlResult?.sql) {
      navigator.clipboard.writeText(nlResult.sql);
    }
  };

  // Are we in any loading phase?
  const isGenerating = askMut.isPending;
  const isExecuting = execMut.isPending || isRunning;
  const isLoading = isGenerating || isExecuting;

  return (
    <div className="flex flex-col h-[calc(100vh-56px)]">
      {/* Input area */}
      <div className="p-6 bg-white border-b">
        <div className="max-w-4xl mx-auto">
          <div className="flex items-center gap-2 mb-3">
            <Sparkles size={20} className="text-folio-600" />
            <h2 className="text-lg font-semibold">Ask AI</h2>
          </div>
          <p className="text-sm text-gray-500 mb-4">
            Describe the report you need in plain English. The AI will generate and
            run a query against the FOLIO LDP schema.
          </p>

          <div className="flex gap-2">
            <textarea
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault();
                  handleSubmit();
                }
              }}
              placeholder="Describe the report you want…"
              className="flex-1 border rounded-lg px-4 py-3 text-sm resize-none h-20 focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
            />
            <div className="flex flex-col gap-2 self-end">
              <button
                onClick={handleSubmit}
                disabled={!prompt.trim() || askMut.isPending}
                className="bg-folio-600 text-white px-4 py-3 rounded-lg hover:bg-folio-700 disabled:opacity-50 transition-colors"
              >
                {askMut.isPending ? (
                  <RotateCcw size={18} className="animate-spin" />
                ) : (
                  <Send size={18} />
                )}
              </button>
              {/* History popover trigger */}
              {history.length > 0 && (
                <div className="relative" ref={historyRef}>
                  <button
                    onClick={() => setHistoryOpen((o) => !o)}
                    className={`w-full flex items-center justify-center px-4 py-2 rounded-lg border transition-colors ${
                      historyOpen
                        ? 'bg-folio-50 text-folio-700 border-folio-300'
                        : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50 border-gray-200'
                    }`}
                    title="Recent questions"
                  >
                    <Clock size={16} />
                  </button>
                  {historyOpen && (
                    <div className="absolute right-0 top-full mt-1 w-96 max-h-80 overflow-y-auto bg-white border border-gray-200 rounded-xl shadow-lg z-40">
                      <div className="px-3 py-2 border-b text-xs font-semibold text-gray-500 uppercase tracking-wide">
                        Recent Questions
                      </div>
                      {history.map((h, i) => (
                        <button
                          key={i}
                          onClick={() => {
                            setPrompt(h.prompt);
                            setNlResult(h.result);
                            resetJob();
                            setActiveJobId(null);
                            setDetailTab('results');
                            setHistoryOpen(false);
                          }}
                          className="block w-full text-left text-sm text-gray-600 hover:text-folio-600 hover:bg-gray-50 px-3 py-2.5 border-b border-gray-50 last:border-0"
                        >
                          <span className="line-clamp-2">{h.prompt}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>

          {/* Example chips */}
          <div className="flex flex-wrap gap-2 mt-3">
            {EXAMPLE_PROMPTS.map((ex, i) => (
              <button
                key={i}
                onClick={() => {
                  setPrompt(ex);
                }}
                className="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1 rounded-full transition-colors"
              >
                {ex}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Success toast */}
      {saveSuccess && (
        <div className="max-w-4xl mx-auto mt-2 px-6">
          <div className="flex items-center gap-2 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm text-green-700">
            <Check size={14} />
            {saveSuccess}
          </div>
        </div>
      )}

      {/* Results area */}
      <div className="flex-1 overflow-y-auto">
        {/* Errors — always visible regardless of tab */}
        {askMut.isError && (
          <div className="max-w-4xl mx-auto m-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            AI error: {String(askMut.error)}
          </div>
        )}
        {nlResult && execMut.isError && (
          <div className="max-w-4xl mx-auto mx-4 mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            Submit error: {String(execMut.error)}
          </div>
        )}
        {nlResult && jobError && (
          <div className="max-w-4xl mx-auto mx-4 mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            Execution error: {jobError}
          </div>
        )}

        {/* Two-phase loading indicator */}
        {isLoading && (
          <div className="flex items-center justify-center py-12">
            {isGenerating ? (
              <div className="flex flex-col items-center gap-3 text-gray-500">
                <Loader2 size={24} className="animate-spin text-folio-600" />
                <span className="text-sm">Generating query with Gemini…</span>
              </div>
            ) : (
              <div className="max-w-md w-full mx-4 bg-blue-50 border border-blue-200 rounded-xl p-5">
                <div className="flex items-start gap-3">
                  <Loader2 size={20} className="animate-spin text-blue-600 mt-0.5 flex-shrink-0" />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold text-blue-800">
                      {job?.status === 'pending' ? 'Queued — waiting for worker…' : 'Running query…'}
                    </div>
                    <div className="text-sm text-blue-600 mt-1">
                      Elapsed: <span className="font-mono font-medium">
                        {elapsedSeconds < 60 ? `${elapsedSeconds}s` : `${Math.floor(elapsedSeconds / 60)}m ${elapsedSeconds % 60}s`}
                      </span>
                      {elapsedSeconds >= 30 && (
                        <span className="ml-2 text-blue-500">— this is a large query, please wait…</span>
                      )}
                    </div>
                    <div className="mt-3 text-xs text-blue-500">
                      You can navigate away — the query will keep running.{' '}
                      <a href="/history" className="underline font-medium hover:text-blue-700">Check History →</a>
                    </div>
                  </div>
                  <button
                    onClick={cancelJobFn}
                    className="flex items-center gap-1 text-xs text-red-600 hover:text-red-800 border border-red-200 rounded px-2 py-1 flex-shrink-0"
                    title="Cancel query"
                  >
                    <Square size={11} /> Cancel
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Main content — only show when not in loading state */}
        {nlResult && !isLoading && (
          <div className="max-w-4xl mx-auto p-6 space-y-4">
            {/* Tab toggle bar */}
            <div className="flex items-center gap-1 border-b pb-0">
              <button
                onClick={() => setDetailTab('results')}
                className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px ${
                  detailTab === 'results'
                    ? 'border-folio-600 text-folio-700'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                }`}
              >
                <Table2 size={14} />
                Results
                {results && (
                  <span className="ml-1 px-1.5 py-0.5 bg-gray-100 text-gray-600 text-xs rounded-full">
                    {results.rowCount.toLocaleString()}
                  </span>
                )}
              </button>
              <button
                onClick={() => setDetailTab('details')}
                className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px ${
                  detailTab === 'details'
                    ? 'border-folio-600 text-folio-700'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                }`}
              >
                <Code2 size={14} />
                SQL &amp; Details
              </button>
            </div>

            {/* ===== Results tab ===== */}
            {detailTab === 'results' && (
              <>
                {results ? (
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <div className="text-xs text-gray-500">
                        {results.rowCount.toLocaleString()} row{results.rowCount !== 1 ? 's' : ''}
                        {results.executionTimeMs != null && (
                          <> &middot; {(results.executionTimeMs / 1000).toFixed(2)}s</>
                        )}
                      </div>
                      <div className="flex items-center gap-3">
                        <button
                          onClick={() => setDetailTab('details')}
                          className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                        >
                          <Code2 size={12} /> View SQL
                        </button>
                        <button
                          onClick={() => setModalOpen(true)}
                          className="flex items-center gap-1 text-xs text-folio-600 hover:text-folio-800"
                        >
                          <Maximize2 size={12} /> Expand
                        </button>
                      </div>
                    </div>
                    <ResultsTable data={results} />
                  </div>
                ) : (
                  <div className="flex items-center justify-center h-32 text-gray-400 text-sm">
                    No results yet — query may still be running.
                  </div>
                )}
              </>
            )}

            {/* ===== SQL & Details tab ===== */}
            {detailTab === 'details' && (
              <div className="space-y-4">
                {/* Explanation */}
                {nlResult.explanation && (
                  <div className="bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-800">
                    <strong>AI Explanation:</strong> {nlResult.explanation}
                  </div>
                )}

                {/* Warnings */}
                {nlResult.warnings && nlResult.warnings.length > 0 && (
                  <div className="bg-yellow-50 border border-yellow-100 rounded-lg p-3 text-sm text-yellow-700">
                    {nlResult.warnings.map((w, i) => (
                      <div key={i}>⚠ {w}</div>
                    ))}
                  </div>
                )}

                {/* SQL + action buttons */}
                {nlResult.sql && (
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <h3 className="text-sm font-semibold">Generated SQL</h3>
                      <div className="flex gap-2">
                        <button
                          onClick={handleCopy}
                          className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                        >
                          <Copy size={12} /> Copy
                        </button>
                        <button
                          onClick={() => setSaveOpen(true)}
                          className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                        >
                          <Save size={12} /> Save
                        </button>
                        {lastSavedId && (
                          <button
                            onClick={() => promoteMut.mutate(lastSavedId)}
                            disabled={promoteMut.isPending}
                            className="flex items-center gap-1 text-xs text-purple-600 hover:text-purple-800"
                          >
                            <FileBarChart size={12} />
                            {promoteMut.isPending ? 'Promoting…' : 'Save as Report'}
                          </button>
                        )}
                        {!isRunning ? (
                          <button
                            onClick={() => execMut.mutate(nlResult.sql)}
                            disabled={execMut.isPending}
                            className="flex items-center gap-1 bg-green-600 text-white text-xs px-3 py-1 rounded hover:bg-green-700 disabled:opacity-50"
                          >
                            <Play size={12} />
                            {execMut.isPending ? 'Submitting…' : 'Re-run Query'}
                          </button>
                        ) : (
                          <button
                            onClick={cancelJobFn}
                            className="flex items-center gap-1 bg-red-600 text-white text-xs px-3 py-1 rounded hover:bg-red-700"
                          >
                            <Square size={12} /> Cancel
                          </button>
                        )}
                        <button
                          onClick={() => {
                            setCorrecting(true);
                            setCorrectedSql(nlResult.sql);
                          }}
                          className="flex items-center gap-1 text-xs text-amber-600 hover:text-amber-800"
                          title="Correct this SQL — your fix teaches the AI"
                        >
                          <ThumbsDown size={12} /> Correct
                        </button>
                      </div>
                    </div>
                    <SqlPreview sql={nlResult.sql} height="180px" />

                    {/* Correction panel */}
                    {correcting && (
                      <div className="mt-3 border border-amber-200 bg-amber-50 rounded-lg p-4">
                        <div className="flex items-center justify-between mb-3">
                          <div className="flex items-center gap-2">
                            <Pencil size={14} className="text-amber-600" />
                            <h4 className="text-sm font-semibold text-amber-800">Correct this query</h4>
                          </div>
                          <button onClick={() => setCorrecting(false)} className="text-gray-400 hover:text-gray-600">
                            <X size={14} />
                          </button>
                        </div>
                        <p className="text-xs text-amber-700 mb-3">
                          Edit the SQL below to fix the issue. Your correction will be saved as a training example
                          so the AI learns from it for future queries.
                        </p>
                        <textarea
                          value={correctedSql}
                          onChange={(e) => setCorrectedSql(e.target.value)}
                          className="w-full border border-amber-300 rounded px-3 py-2 text-xs font-mono h-40 resize-none focus:ring-2 focus:ring-amber-300 outline-none bg-white"
                        />
                        <input
                          value={correctionNotes}
                          onChange={(e) => setCorrectionNotes(e.target.value)}
                          placeholder="What was wrong? (optional note)"
                          className="w-full border border-amber-300 rounded px-3 py-2 text-sm mt-2 focus:ring-2 focus:ring-amber-300 outline-none bg-white"
                        />
                        <div className="flex justify-end gap-2 mt-3">
                          <button
                            onClick={() => setCorrecting(false)}
                            className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50"
                          >
                            Cancel
                          </button>
                          <button
                            onClick={() => correctionMut.mutate()}
                            disabled={correctionMut.isPending || !correctedSql.trim()}
                            className="flex items-center gap-1 px-3 py-1.5 text-sm bg-amber-600 text-white rounded hover:bg-amber-700 disabled:opacity-50"
                          >
                            <Save size={12} />
                            {correctionMut.isPending ? 'Saving…' : 'Save Correction'}
                          </button>
                        </div>
                        {correctionMut.isError && (
                          <div className="mt-2 text-xs text-red-600">
                            Error: {String(correctionMut.error)}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* Empty state — only when nothing happening */}
        {!nlResult && !isLoading && (
          <div className="flex items-center justify-center h-64 text-gray-400 text-sm">
            Ask a question above and the AI will generate a report for you.
          </div>
        )}
      </div>

      {/* Results modal (expanded view) */}
      {modalOpen && results && (
        <ResultsModal
          data={results}
          onClose={() => setModalOpen(false)}
          title={history[0]?.prompt}
        />
      )}

      {/* Save dialog */}
      {saveOpen && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-xl p-6 w-96">
            <h3 className="font-semibold mb-4">Save AI Query</h3>
            <input
              placeholder="Query name"
              value={saveName}
              onChange={(e) => setSaveName(e.target.value)}
              className="border rounded w-full px-3 py-2 mb-3 text-sm"
              autoFocus
            />
            <textarea
              placeholder="Description (optional)"
              value={saveDesc}
              onChange={(e) => setSaveDesc(e.target.value)}
              className="border rounded w-full px-3 py-2 mb-3 text-sm h-20 resize-none"
            />
            <div className="bg-gray-50 rounded p-2 mb-4 text-xs text-gray-500">
              <strong>Question:</strong> {history[0]?.prompt || prompt}
            </div>
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setSaveOpen(false)}
                className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={() => savedMut.mutate()}
                disabled={!saveName || savedMut.isPending}
                className="px-3 py-1.5 text-sm bg-folio-600 text-white rounded hover:bg-folio-700 disabled:opacity-50"
              >
                {savedMut.isPending ? 'Saving…' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
