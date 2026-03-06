import { useState, useEffect, useRef, useCallback } from 'react';
import { checkJobStatus, cancelJob } from '../api/client';
import type { JobStatusResponse, ExecuteResponse } from '../types';

const POLL_INTERVAL = 2000;

interface UseJobPollingReturn {
  /** Current job status response */
  job: JobStatusResponse | null;
  /** Converted ExecuteResponse when job completes */
  results: ExecuteResponse | null;
  /** Whether the job is still in progress */
  isRunning: boolean;
  /** Error message if failed */
  error: string | null;
  /** Elapsed wall-clock seconds since the job was submitted */
  elapsedSeconds: number;
  /** Cancel the current job */
  cancel: () => void;
  /** Clear the job state */
  reset: () => void;
}

/**
 * Polls a background query job until it reaches a terminal state.
 * Converts the completed job response into an ExecuteResponse for the ResultsTable.
 */
export function useJobPolling(jobId: string | null): UseJobPollingReturn {
  const [job, setJob] = useState<JobStatusResponse | null>(null);
  const [results, setResults] = useState<ExecuteResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [elapsedSeconds, setElapsedSeconds] = useState(0);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const elapsedTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const activeJobId = useRef<string | null>(null);

  const stopPolling = useCallback(() => {
    if (intervalRef.current) {
      clearInterval(intervalRef.current);
      intervalRef.current = null;
    }
    if (elapsedTimerRef.current) {
      clearInterval(elapsedTimerRef.current);
      elapsedTimerRef.current = null;
    }
  }, []);

  const reset = useCallback(() => {
    stopPolling();
    activeJobId.current = null;
    setJob(null);
    setResults(null);
    setError(null);
    setElapsedSeconds(0);
  }, [stopPolling]);

  const cancelCurrent = useCallback(async () => {
    if (activeJobId.current) {
      try {
        await cancelJob(activeJobId.current);
      } catch {
        // ignore cancel errors
      }
      stopPolling();
      setJob((prev) => prev ? { ...prev, status: 'cancelled', progressMessage: 'Cancelled' } : null);
      setError('Query was cancelled');
    }
  }, [stopPolling]);

  useEffect(() => {
    if (!jobId) return;

    // Reset state for new job
    activeJobId.current = jobId;
    setResults(null);
    setError(null);
    setElapsedSeconds(0);
    setJob({
      jobId,
      status: 'pending',
      sql: '',
      progressMessage: 'Queued…',
      createdAt: null,
      startedAt: null,
      completedAt: null,
    });

    // Start elapsed-seconds wall-clock timer
    elapsedTimerRef.current = setInterval(() => {
      setElapsedSeconds((s) => s + 1);
    }, 1000);

    const poll = async () => {
      // Don't poll if this job is no longer active
      if (activeJobId.current !== jobId) return;

      try {
        const status = await checkJobStatus(jobId);
        if (activeJobId.current !== jobId) return;

        setJob(status);

        if (status.status === 'completed') {
          stopPolling();
          if (status.outputMode === 'file') {
            setResults({
              columns: [],
              rows: [],
              rowCount: status.rowCount || 0,
              executionTimeMs: status.executionTimeMs || 0,
              sql: status.sql,
              outputMode: 'file',
              downloadUrl: status.downloadUrl,
            });
          } else {
            // Convert to ExecuteResponse
            setResults({
              columns: status.columns || [],
              rows: status.rows || [],
              rowCount: status.rowCount || 0,
              executionTimeMs: status.executionTimeMs || 0,
              sql: status.sql,
            });
          }
        } else if (status.status === 'failed') {
          stopPolling();
          setError(status.error || 'Query execution failed');
        } else if (status.status === 'cancelled') {
          stopPolling();
          setError('Query was cancelled');
        }
      } catch (err) {
        // Network error during poll — keep trying
        console.warn('Poll error:', err);
      }
    };

    // Poll immediately, then on interval
    poll();
    intervalRef.current = setInterval(poll, POLL_INTERVAL);

    return () => {
      stopPolling();
    };
  }, [jobId, stopPolling]);

  const isRunning = job?.status === 'pending' || job?.status === 'pending_export' || job?.status === 'running';

  return { job, results, isRunning, error, elapsedSeconds, cancel: cancelCurrent, reset };
}
