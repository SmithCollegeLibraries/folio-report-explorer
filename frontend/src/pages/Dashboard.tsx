import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { listPinned, submitQuery, togglePin } from '../api/client';
import { useJobPolling } from '../hooks/useJobPolling';
import ResultsModal from '../components/ResultsModal';
import type { SavedQuery, ExecuteResponse } from '../types';
import {
  LayoutDashboard, Pin, PinOff, Maximize2, Wrench,
  MessageSquare, FileBarChart, Loader2, AlertCircle, Sparkles,
} from 'lucide-react';

/** A single dashboard card that auto-runs a pinned query */
function DashboardCard({
  query,
  onUnpin,
  onExpand,
}: {
  query: SavedQuery;
  onUnpin: () => void;
  onExpand: (data: ExecuteResponse, title: string) => void;
}) {
  const [jobId, setJobId] = useState<string | null>(null);
  const [autoRan, setAutoRan] = useState(false);

  const { results, isRunning, error } = useJobPolling(jobId);

  const runMut = useMutation({
    mutationFn: () => submitQuery(query.generated_sql || '', {}, 'builder'),
    onSuccess: (data: { jobId: string }) => setJobId(data.jobId),
  });

  // Auto-run on mount
  useEffect(() => {
    if (query.generated_sql && !autoRan) {
      setAutoRan(true);
      runMut.mutate();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query.generated_sql]);

  return (
    <div className="border rounded-xl bg-white shadow-sm hover:shadow-md transition-shadow flex flex-col">
      {/* Card header */}
      <div className="px-4 py-3 border-b flex items-center gap-2">
        <div className="flex-1 min-w-0">
          <h3 className="font-medium text-sm truncate" title={query.name}>
            {query.name}
          </h3>
          {query.nl_prompt && (
            <p className="text-xs text-gray-400 truncate mt-0.5" title={query.nl_prompt}>
              <Sparkles size={10} className="inline mr-1 text-purple-400" />
              {query.nl_prompt}
            </p>
          )}
          {query.description && !query.nl_prompt && (
            <p className="text-xs text-gray-400 truncate mt-0.5">{query.description}</p>
          )}
        </div>
        <div className="flex items-center gap-1 flex-shrink-0">
          {query.source === 'nl' && (
            <span className="text-[10px] bg-purple-50 text-purple-600 px-1.5 py-0.5 rounded font-medium">AI</span>
          )}
          {results && (
            <button
              onClick={() => onExpand(results, query.name)}
              className="p-1 text-gray-400 hover:text-folio-600 rounded"
              title="Expand results"
            >
              <Maximize2 size={14} />
            </button>
          )}
          <button
            onClick={onUnpin}
            className="p-1 text-gray-400 hover:text-red-500 rounded"
            title="Unpin from dashboard"
          >
            <PinOff size={14} />
          </button>
        </div>
      </div>

      {/* Card body */}
      <div className="flex-1 p-3 overflow-hidden" style={{ maxHeight: '300px', overflow: 'auto' }}>
        {isRunning && (
          <div className="flex items-center justify-center py-8">
            <Loader2 size={20} className="animate-spin text-folio-600" />
            <span className="ml-2 text-sm text-gray-500">Running…</span>
          </div>
        )}

        {error && (
          <div className="flex items-center gap-2 text-sm text-red-600 py-4">
            <AlertCircle size={14} />
            {error}
          </div>
        )}

        {runMut.isError && (
          <div className="flex items-center gap-2 text-sm text-red-600 py-4">
            <AlertCircle size={14} />
            {String(runMut.error)}
          </div>
        )}

        {results && (
          <div className="text-xs">
            {/* Compact summary */}
            <div className="flex gap-3 text-gray-500 mb-2">
              <span><strong>{results.rowCount}</strong> rows</span>
              <span><strong>{results.executionTimeMs}</strong>ms</span>
            </div>
            {/* Compact table — show first 5 rows */}
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="bg-gray-50 border-b">
                    {results.columns.slice(0, 5).map((col) => (
                      <th key={col} className="px-2 py-1 text-left font-semibold text-gray-600 truncate max-w-[120px]">
                        {col}
                      </th>
                    ))}
                    {results.columns.length > 5 && (
                      <th className="px-2 py-1 text-gray-400">+{results.columns.length - 5}</th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {results.rows.slice(0, 5).map((row, ri) => (
                    <tr key={ri} className="border-b">
                      {results.columns.slice(0, 5).map((col) => (
                        <td key={col} className="px-2 py-1 truncate max-w-[120px] font-mono">
                          {row[col] === null ? (
                            <span className="text-gray-300 italic">null</span>
                          ) : (
                            String(row[col])
                          )}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {results.rowCount > 5 && (
              <button
                onClick={() => onExpand(results, query.name)}
                className="mt-2 text-folio-600 hover:text-folio-800 text-xs"
              >
                View all {results.rowCount} rows →
              </button>
            )}
          </div>
        )}

        {!isRunning && !results && !error && !runMut.isError && !query.generated_sql && (
          <div className="text-center py-6 text-gray-400 text-sm">
            No SQL to run
          </div>
        )}
      </div>
    </div>
  );
}

export default function Dashboard() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [modalData, setModalData] = useState<{ data: ExecuteResponse; title: string } | null>(null);

  const { data: pinned, isLoading, error } = useQuery({
    queryKey: ['pinnedQueries'],
    queryFn: listPinned,
    refetchInterval: 60000, // Refresh every minute
  });

  const unpinMut = useMutation({
    mutationFn: (id: number) => togglePin(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['pinnedQueries'] }),
  });

  return (
    <div className="min-h-[calc(100vh-56px)] bg-gray-50">
      {/* Header */}
      <div className="bg-white border-b px-6 py-5">
        <div className="max-w-screen-xl mx-auto">
          <div className="flex items-center gap-3">
            <LayoutDashboard size={24} className="text-folio-600" />
            <div>
              <h1 className="text-xl font-bold">Dashboard</h1>
              <p className="text-sm text-gray-500">
                Pinned queries run automatically and show live results.
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Content */}
      <div className="max-w-screen-xl mx-auto p-6">
        {isLoading && (
          <div className="flex items-center justify-center py-24">
            <Loader2 size={24} className="animate-spin text-folio-600" />
            <span className="ml-2 text-gray-500">Loading dashboard…</span>
          </div>
        )}

        {error && (
          <div className="text-center py-16 text-red-500">
            Failed to load pinned queries: {String(error)}
          </div>
        )}

        {/* Pinned query cards */}
        {pinned && pinned.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            {pinned.map((q) => (
              <DashboardCard
                key={q.id}
                query={q}
                onUnpin={() => unpinMut.mutate(q.id)}
                onExpand={(data, title) => setModalData({ data, title })}
              />
            ))}
          </div>
        )}

        {/* Empty state */}
        {pinned && pinned.length === 0 && (
          <div className="text-center py-20">
            <Pin size={40} className="mx-auto text-gray-300 mb-4" />
            <h2 className="text-lg font-semibold text-gray-600 mb-2">No pinned queries yet</h2>
            <p className="text-sm text-gray-400 mb-6 max-w-md mx-auto">
              Pin saved queries to your dashboard to see live results at a glance.
              Save a query from the Query Builder or Ask AI, then pin it from the Saved Queries page.
            </p>
            <div className="flex justify-center gap-3">
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
      </div>

      {/* Expanded results modal */}
      {modalData && (
        <ResultsModal
          data={modalData.data}
          onClose={() => setModalData(null)}
          title={modalData.title}
        />
      )}
    </div>
  );
}
