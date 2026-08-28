import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Ask from './Ask';

const apiMocks = vi.hoisted(() => ({
  askNl: vi.fn(),
  submitQuery: vi.fn(),
  fetchQueryReuseCandidate: vi.fn(),
  saveQueryFeedback: vi.fn(),
  recordQueryMemorySignal: vi.fn(),
}));

vi.mock('../api/client', () => ({
  askNl: apiMocks.askNl,
  submitQuery: apiMocks.submitQuery,
  fetchQueryReuseCandidate: apiMocks.fetchQueryReuseCandidate,
  saveQueryFeedback: apiMocks.saveQueryFeedback,
  recordQueryMemorySignal: apiMocks.recordQueryMemorySignal,
  saveQuery: vi.fn(),
  promoteToReport: vi.fn(),
  submitCorrection: vi.fn(),
  saveCampusPreference: vi.fn().mockResolvedValue(undefined),
  downloadExportCsv: vi.fn(),
  saveClarificationResolution: vi.fn(),
  replaceQueryFeedback: vi.fn(),
  recordQueryReuseDecision: vi.fn(),
}));

vi.mock('../hooks/useAuth', () => ({
  useAuth: () => ({ user: null, authEnabled: false }),
}));

vi.mock('../hooks/useJobPolling', () => ({
  useJobPolling: () => ({
    job: null,
    results: {
      columns: ['title'],
      rows: [],
      rowCount: 0,
      executionTimeMs: 1,
      outputMode: 'table',
    },
    isRunning: false,
    error: null,
    cancel: vi.fn(),
    reset: vi.fn(),
    elapsedSeconds: 0,
  }),
}));

vi.mock('../components/ToastProvider', () => ({
  useToast: () => ({ error: vi.fn(), success: vi.fn() }),
}));
vi.mock('../components/SqlPreview', () => ({ default: () => null }));
vi.mock('../components/ResultsTable', () => ({ default: () => null }));
vi.mock('../components/ResultsModal', () => ({ default: () => null }));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((fulfill) => {
    resolve = fulfill;
  });
  return { promise, resolve };
}

function renderAsk() {
  const client = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <Ask />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function submitQuestion(question: string) {
  const input = screen.getAllByPlaceholderText('Describe the report you want...')[0];
  fireEvent.change(input, { target: { value: question } });
  fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });
}

beforeEach(() => {
  const values = new Map<string, string>();
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => values.set(key, value),
    removeItem: (key: string) => values.delete(key),
    clear: () => values.clear(),
  });
  vi.clearAllMocks();
  apiMocks.fetchQueryReuseCandidate.mockResolvedValue({ match: null });
  apiMocks.saveQueryFeedback.mockResolvedValue({
    feedbackId: 1,
    resultAccuracy: 'accurate',
    reuseSuppressed: false,
    message: 'Feedback saved.',
  });
  apiMocks.recordQueryMemorySignal.mockResolvedValue({ ok: true, signal: 'follow_up', count: 1 });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Ask query-memory mutation targets', () => {
  it('does not let a stale execution response redirect feedback to an older generation', async () => {
    const firstExecution = deferred<{ jobId: string; generationId: string }>();
    apiMocks.askNl
      .mockResolvedValueOnce({ sql: 'SELECT 1', generationId: 'source-1', generationProvenance: 'ai_built' })
      .mockResolvedValueOnce({ sql: 'SELECT 2', generationId: 'source-2', generationProvenance: 'ai_built' });
    apiMocks.submitQuery
      .mockReturnValueOnce(firstExecution.promise)
      .mockResolvedValueOnce({ jobId: 'job-2', generationId: 'execution-2' });

    renderAsk();
    submitQuestion('First report');
    await waitFor(() => expect(apiMocks.submitQuery).toHaveBeenCalledTimes(1));

    submitQuestion('Second report');
    await waitFor(() => expect(apiMocks.submitQuery).toHaveBeenCalledTimes(2));

    await act(async () => {
      firstExecution.resolve({ jobId: 'job-1', generationId: 'execution-1' });
      await firstExecution.promise;
    });
    fireEvent.click(await screen.findByRole('button', { name: 'Yes' }));

    await waitFor(() => {
      expect(apiMocks.saveQueryFeedback).toHaveBeenCalledWith({
        generationId: 'execution-2',
        queryJobId: 'job-2',
        resultAccuracy: 'accurate',
        feedbackNote: null,
      });
    });
  });

  it('records a completed follow-up against the generation that it followed', async () => {
    const firstExecution = deferred<{ jobId: string; generationId: string }>();
    const followUpGeneration = deferred<Record<string, unknown>>();
    apiMocks.askNl
      .mockResolvedValueOnce({ sql: 'SELECT 1', generationId: 'source-1', generationProvenance: 'ai_built' })
      .mockResolvedValueOnce({ sql: 'SELECT 2', generationId: 'source-2', generationProvenance: 'ai_built' })
      .mockReturnValueOnce(followUpGeneration.promise);
    apiMocks.submitQuery
      .mockReturnValueOnce(firstExecution.promise)
      .mockResolvedValueOnce({ jobId: 'job-2', generationId: 'execution-2' })
      .mockResolvedValueOnce({ jobId: 'job-follow-up', generationId: 'execution-follow-up' });

    renderAsk();
    submitQuestion('First report');
    await waitFor(() => expect(apiMocks.submitQuery).toHaveBeenCalledTimes(1));

    submitQuestion('Second report');
    await waitFor(() => expect(apiMocks.submitQuery).toHaveBeenCalledTimes(2));

    fireEvent.click(screen.getByRole('button', { name: 'Ask follow-up' }));
    submitQuestion('Break it down by year');
    await waitFor(() => expect(apiMocks.askNl).toHaveBeenCalledTimes(3));

    await act(async () => {
      firstExecution.resolve({ jobId: 'job-1', generationId: 'execution-1' });
      await firstExecution.promise;
    });

    await act(async () => {
      followUpGeneration.resolve({
        sql: 'SELECT 2',
        generationId: 'source-follow-up',
        generationProvenance: 'ai_built',
      });
      await followUpGeneration.promise;
    });

    await waitFor(() => {
      expect(apiMocks.recordQueryMemorySignal).toHaveBeenCalledWith({
        generationId: 'execution-2',
        queryJobId: 'job-2',
        signal: 'follow_up',
      });
    });
  });
});
