import { useEffect, useRef, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { listSaved, deleteSaved, togglePin, promoteToReport, refreshDashboardCard, checkJobStatus } from '../api/client';
import SqlPreview from '../components/SqlPreview';
import ResultsTable from '../components/ResultsTable';
import type { SavedQuery, ExecuteResponse } from '../types';
import {
  Trash2, Play, ChevronDown, ChevronRight, Clock,
  Pin, PinOff, FileBarChart, Bot, Wrench,
} from 'lucide-react';

export default function SavedQueries() {
  const queryClient = useQueryClient();
  const [expanded, setExpanded] = useState<number | null>(null);
  const [results, setResults] = useState<Record<number, ExecuteResponse>>({});
  const [runningIds, setRunningIds] = useState<Set<number>>(new Set());
  const pollingTimersRef = useRef<Record<number, ReturnType<typeof setTimeout>>>({});

  useEffect(() => {
    return () => {
      Object.values(pollingTimersRef.current).forEach((timer) => clearTimeout(timer));
      pollingTimersRef.current = {};
    };
  }, []);

  const { data: queries, isLoading, error } = useQuery({
    queryKey: ['savedQueries'],
    queryFn: listSaved,
  });

  const deleteMut = useMutation({
    mutationFn: (id: number) => deleteSaved(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['savedQueries'] }),
  });

  const pollJobUntilDone = async (savedQueryId: number, jobId: string) => {
    const poll = async (): Promise<void> => {
      try {
        const status = await checkJobStatus(jobId);

        if (status.status === 'completed') {
          setResults((prev) => ({
            ...prev,
            [savedQueryId]: {
              columns: status.columns || [],
              rows: status.rows || [],
              rowCount: status.rowCount || 0,
              executionTimeMs: status.executionTimeMs || 0,
              sql: status.sql || '',
              outputMode: status.outputMode,
              downloadUrl: status.downloadUrl,
            },
          }));
          setRunningIds((prev) => {
            const next = new Set(prev);
            next.delete(savedQueryId);
            return next;
          });
          delete pollingTimersRef.current[savedQueryId];
          void queryClient.invalidateQueries({ queryKey: ['savedQueries'] });
          return;
        }

        if (status.status === 'failed' || status.status === 'cancelled') {
          setRunningIds((prev) => {
            const next = new Set(prev);
            next.delete(savedQueryId);
            return next;
          });
          delete pollingTimersRef.current[savedQueryId];
          return;
        }

        pollingTimersRef.current[savedQueryId] = setTimeout(() => {
          void poll();
        }, 2000);
      } catch {
        setRunningIds((prev) => {
          const next = new Set(prev);
          next.delete(savedQueryId);
          return next;
        });
        delete pollingTimersRef.current[savedQueryId];
      }
    };

    await poll();
  };

  const loadCachedResults = async (q: SavedQuery) => {
    if (!q.last_job_id) return;
    try {
      const status = await checkJobStatus(q.last_job_id);
      if (status.status === 'completed') {
        setResults((prev) => ({
          ...prev,
          [q.id]: {
            columns: status.columns || [],
            rows: status.rows || [],
            rowCount: status.rowCount || 0,
            executionTimeMs: status.executionTimeMs || 0,
            sql: status.sql || q.generated_sql || '',
            outputMode: status.outputMode,
            downloadUrl: status.downloadUrl,
          },
        }));
      }
    } catch {
      // ignore stale job IDs
    }
  };

  const runSavedQuery = async (q: SavedQuery) => {
    setExpanded(q.id);

    if (q.last_job_id) {
      const rerun = window.confirm(
        'This saved query already has stored results.\n\nClick OK to re-run for the latest data.\nClick Cancel to use the saved results.',
      );

      if (!rerun) {
        await loadCachedResults(q);
        return;
      }
    }

    setRunningIds((prev) => new Set(prev).add(q.id));

    try {
      const { jobId } = await refreshDashboardCard(q.id);
      await pollJobUntilDone(q.id, jobId);
    } catch {
      setRunningIds((prev) => {
        const next = new Set(prev);
        next.delete(q.id);
        return next;
      });
    }
  };

  const pinMut = useMutation({
    mutationFn: (id: number) => togglePin(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['savedQueries'] });
      queryClient.invalidateQueries({ queryKey: ['pinnedQueries'] });
    },
  });

  const promoteMut = useMutation({
    mutationFn: (id: number) => promoteToReport(id),
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96 text-gray-500">
        Loading saved queries…
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-96 text-red-500">
        Failed to load saved queries: {String(error)}
      </div>
    );
  }

  const items: SavedQuery[] = queries || [];

  return (
    <div className="max-w-4xl mx-auto p-6">
      <h2 className="text-xl font-semibold mb-6">Saved Queries</h2>

      {items.length === 0 ? (
        <div className="text-center py-16 text-gray-400">
          <p className="text-lg mb-2">No saved queries yet</p>
          <p className="text-sm">
            Build a query in the Query Builder or Ask AI, then save it here.
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {items.map((q) => {
            const isExpanded = expanded === q.id;
            const qResults = results[q.id];
            const isRunning = runningIds.has(q.id);

            return (
              <div key={q.id} className="border rounded-lg bg-white">
                {/* Header */}
                <div className="flex items-center gap-3 px-4 py-3">
                  <button
                    onClick={() => {
                      const nextExpanded = isExpanded ? null : q.id;
                      setExpanded(nextExpanded);
                      if (nextExpanded === q.id && !results[q.id] && q.last_job_id) {
                        void loadCachedResults(q);
                      }
                    }}
                    className="text-gray-500 hover:text-gray-700"
                  >
                    {isExpanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                  </button>

                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <h3 className="font-medium text-sm truncate">{q.name}</h3>
                      {q.source === 'nl' && (
                        <span className="inline-flex items-center gap-0.5 text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-700">
                          <Bot size={10} /> AI
                        </span>
                      )}
                      {q.source === 'builder' && (
                        <span className="inline-flex items-center gap-0.5 text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700">
                          <Wrench size={10} /> Builder
                        </span>
                      )}
                      {q.is_pinned && (
                        <span className="text-amber-500" title="Pinned to Dashboard">
                          <Pin size={12} />
                        </span>
                      )}
                    </div>
                    {q.nl_prompt && (
                      <p className="text-xs text-purple-500 truncate italic">"{q.nl_prompt}"</p>
                    )}
                    {q.description && !q.nl_prompt && (
                      <p className="text-xs text-gray-500 truncate">{q.description}</p>
                    )}
                  </div>

                  <div className="flex items-center gap-1 text-xs text-gray-400">
                    <Clock size={12} />
                    {new Date(q.created_at).toLocaleDateString()}
                  </div>

                  <div className="flex gap-1">
                    <button
                      onClick={() => pinMut.mutate(q.id)}
                      disabled={pinMut.isPending}
                      className={`p-1 rounded ${q.is_pinned ? 'text-amber-500 hover:text-amber-600' : 'text-gray-400 hover:text-amber-500'}`}
                      title={q.is_pinned ? 'Unpin from Dashboard' : 'Pin to Dashboard'}
                    >
                      {q.is_pinned ? <PinOff size={14} /> : <Pin size={14} />}
                    </button>
                    <button
                      onClick={() => promoteMut.mutate(q.id)}
                      disabled={promoteMut.isPending}
                      className="text-gray-400 hover:text-indigo-600 p-1"
                      title="Save as Report"
                    >
                      <FileBarChart size={14} />
                    </button>
                    {q.generated_sql && (
                      <button
                        onClick={() => void runSavedQuery(q)}
                        disabled={isRunning}
                        className="flex items-center gap-1 bg-green-600 text-white text-xs px-2 py-1 rounded hover:bg-green-700 disabled:opacity-50"
                      >
                        <Play size={12} /> {isRunning ? 'Running…' : 'Run'}
                      </button>
                    )}
                    <button
                      onClick={() => {
                        if (confirm('Delete this saved query?')) {
                          deleteMut.mutate(q.id);
                        }
                      }}
                      className="text-gray-400 hover:text-red-500 p-1"
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                </div>

                {/* Expanded content */}
                {isExpanded && (
                  <div className="px-4 pb-4 border-t pt-3 space-y-3">
                    {/* NL prompt */}
                    {q.nl_prompt && (
                      <div className="flex items-start gap-2 bg-purple-50 rounded p-2 text-xs text-purple-800">
                        <Bot size={14} className="mt-0.5 shrink-0" />
                        <span>Original question: <em>"{q.nl_prompt}"</em></span>
                      </div>
                    )}

                    {q.description && q.nl_prompt && (
                      <p className="text-xs text-gray-500">{q.description}</p>
                    )}

                    {/* Query definition */}
                    {q.query_definition && (
                      <div className="text-xs text-gray-600">
                        <span className="font-medium">Tables:</span>{' '}
                        {(q.query_definition as any).tables?.join(', ') || '—'}
                        <br />
                        <span className="font-medium">Columns:</span>{' '}
                        {(q.query_definition as any).columns
                          ?.map(
                            (c: any) => `${c.table}.${c.column}${c.alias ? ` as ${c.alias}` : ''}`,
                          )
                          .join(', ') || '—'}
                      </div>
                    )}

                    {/* SQL preview */}
                    {q.generated_sql && (
                      <SqlPreview sql={q.generated_sql} height="140px" />
                    )}

                    {!qResults && q.last_job_id && (
                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => void loadCachedResults(q)}
                          className="flex items-center gap-1 bg-gray-100 text-gray-700 text-xs px-2 py-1 rounded hover:bg-gray-200"
                        >
                          <Clock size={12} /> Load saved result
                        </button>
                        <button
                          onClick={() => void runSavedQuery(q)}
                          disabled={isRunning}
                          className="flex items-center gap-1 bg-green-600 text-white text-xs px-2 py-1 rounded hover:bg-green-700 disabled:opacity-50"
                        >
                          <Play size={12} /> {isRunning ? 'Running…' : 'Re-run latest'}
                        </button>
                      </div>
                    )}

                    {/* Results */}
                    {qResults && (
                      <ResultsTable data={qResults} />
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
