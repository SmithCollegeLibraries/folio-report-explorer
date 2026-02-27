import { useState, useEffect, useCallback } from 'react';
import { History as HistoryIcon, Clock, Database, Eye, ChevronLeft, ChevronRight, User } from 'lucide-react';
import { fetchQueryHistory, checkJobStatus } from '../api/client';
import { useAuth } from '../hooks/useAuth';
import type { HistoryItem, JobStatusResponse } from '../types';

/**
 * Query history page — shows the user's completed queries and lets them
 * re-view results.
 */
export default function History() {
  const { isAdmin } = useAuth();
  const [items, setItems] = useState<HistoryItem[]>([]);
  const [total, setTotal] = useState(0);
  const [offset, setOffset] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Selected job detail
  const [selectedJob, setSelectedJob] = useState<JobStatusResponse | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const limit = 20;

  const load = useCallback(async () => {
    try {
      setLoading(true);
      const data = await fetchQueryHistory(limit, offset);
      setItems(data.items);
      setTotal(data.total);
      setError(null);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to load history');
    } finally {
      setLoading(false);
    }
  }, [offset]);

  useEffect(() => {
    load();
  }, [load]);

  const handleViewResults = async (jobId: string) => {
    try {
      setDetailLoading(true);
      setSelectedJob(null);
      const data = await checkJobStatus(jobId);
      setSelectedJob(data);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to load results');
    } finally {
      setDetailLoading(false);
    }
  };

  const totalPages = Math.ceil(total / limit);
  const currentPage = Math.floor(offset / limit) + 1;

  return (
    <div className="max-w-screen-xl mx-auto p-6">
      <div className="flex items-center gap-3 mb-6">
        <HistoryIcon className="text-folio-600" size={24} />
        <h1 className="text-2xl font-bold text-gray-800">Query History</h1>
        <span className="text-sm text-gray-400 ml-2">
          {total} completed {total === 1 ? 'query' : 'queries'}
        </span>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-3 mb-4 text-red-700 text-sm">
          {error}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Job list */}
        <div>
          {loading ? (
            <div className="flex justify-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-folio-600" />
            </div>
          ) : items.length === 0 ? (
            <div className="text-center py-12 text-gray-400">
              <Database size={40} className="mx-auto mb-3 opacity-50" />
              <p>No completed queries yet.</p>
              <p className="text-sm mt-1">Run a query from the Builder or Ask AI page.</p>
            </div>
          ) : (
            <>
              <div className="space-y-2">
                {items.map((item) => (
                  <button
                    key={item.jobId}
                    onClick={() => handleViewResults(item.jobId)}
                    className={`w-full text-left bg-white border rounded-lg p-4 hover:border-folio-300 transition-colors ${
                      selectedJob?.jobId === item.jobId
                        ? 'border-folio-500 ring-1 ring-folio-200'
                        : 'border-gray-200'
                    }`}
                  >
                    {/* Name / title row */}
                    {item.name ? (
                      <div className="text-sm font-semibold text-gray-800 mb-1 line-clamp-1">{item.name}</div>
                    ) : (
                      <div className="text-sm italic text-gray-400 mb-1">Unnamed query</div>
                    )}
                    <div className="flex items-start justify-between mb-2">
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-folio-600 bg-folio-50 px-2 py-0.5 rounded">
                        {item.source}
                      </span>
                      <span className="text-xs text-gray-400 flex items-center gap-1">
                        <Clock size={12} />
                        {new Date(item.completedAt).toLocaleString()}
                      </span>
                    </div>
                    <pre className="text-xs text-gray-600 font-mono whitespace-pre-wrap line-clamp-2 mb-2">
                      {item.sql}
                    </pre>
                    <div className="flex items-center justify-between text-xs text-gray-400">
                      <div className="flex items-center gap-4">
                        <span>{item.rowCount.toLocaleString()} rows</span>
                        <span>{item.executionTimeMs >= 1000
                          ? `${(item.executionTimeMs / 1000).toFixed(1)}s`
                          : `${item.executionTimeMs}ms`}</span>
                      </div>
                      {isAdmin && item.runBy && (
                        <span className="flex items-center gap-1 text-gray-400">
                          <User size={11} />{item.runBy}
                        </span>
                      )}
                    </div>
                  </button>
                ))}
              </div>

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <button
                    onClick={() => setOffset(Math.max(0, offset - limit))}
                    disabled={offset === 0}
                    className="flex items-center gap-1 px-3 py-1.5 text-sm border rounded disabled:opacity-40 hover:bg-gray-50"
                  >
                    <ChevronLeft size={14} /> Previous
                  </button>
                  <span className="text-sm text-gray-500">
                    Page {currentPage} of {totalPages}
                  </span>
                  <button
                    onClick={() => setOffset(offset + limit)}
                    disabled={offset + limit >= total}
                    className="flex items-center gap-1 px-3 py-1.5 text-sm border rounded disabled:opacity-40 hover:bg-gray-50"
                  >
                    Next <ChevronRight size={14} />
                  </button>
                </div>
              )}
            </>
          )}
        </div>

        {/* Result detail panel */}
        <div>
          {detailLoading ? (
            <div className="flex justify-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-folio-600" />
            </div>
          ) : selectedJob ? (
            <div className="bg-white border rounded-lg overflow-hidden sticky top-4">
              <div className="bg-gray-50 border-b px-4 py-3">
                <div className="flex items-center justify-between">
                  <h3 className="font-medium text-gray-800 flex items-center gap-2">
                    <Eye size={16} className="text-folio-600" />
                    Query Results
                  </h3>
                  <div className="text-xs text-gray-400">
                    {selectedJob.rowCount?.toLocaleString()} rows &middot; {selectedJob.executionTimeMs}ms
                  </div>
                </div>
              </div>

              {/* SQL */}
              <div className="px-4 py-3 border-b">
                <pre className="text-xs font-mono text-gray-600 whitespace-pre-wrap max-h-32 overflow-auto">
                  {selectedJob.sql}
                </pre>
              </div>

              {/* Results table */}
              {selectedJob.columns && selectedJob.rows ? (
                <div className="overflow-auto max-h-[60vh]">
                  <table className="w-full text-xs">
                    <thead className="bg-gray-50 sticky top-0">
                      <tr>
                        {selectedJob.columns.map((col) => (
                          <th
                            key={col}
                            className="text-left p-2 font-medium text-gray-600 border-b whitespace-nowrap"
                          >
                            {col}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {selectedJob.rows.slice(0, 200).map((row, i) => (
                        <tr key={i} className="border-b last:border-b-0 hover:bg-gray-50">
                          {selectedJob.columns!.map((col) => (
                            <td key={col} className="p-2 text-gray-700 whitespace-nowrap max-w-xs truncate">
                              {row[col] != null ? String(row[col]) : <span className="text-gray-300">null</span>}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {selectedJob.rows.length > 200 && (
                    <div className="px-4 py-2 text-xs text-gray-400 text-center border-t">
                      Showing first 200 of {selectedJob.rows.length.toLocaleString()} rows
                    </div>
                  )}
                </div>
              ) : (
                <div className="p-6 text-center text-gray-400 text-sm">
                  Results not available. They may have been cleaned up.
                </div>
              )}
            </div>
          ) : (
            <div className="flex items-center justify-center h-48 text-gray-400 text-sm">
              <p>Select a query to view its results</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
