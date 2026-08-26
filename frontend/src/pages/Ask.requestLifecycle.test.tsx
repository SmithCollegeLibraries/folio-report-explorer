import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Ask from './Ask';

const apiMocks = vi.hoisted(() => ({
  askNl: vi.fn(),
  submitQuery: vi.fn(),
  fetchQueryReuseCandidate: vi.fn(),
}));

const toastMocks = vi.hoisted(() => ({
  error: vi.fn(),
  success: vi.fn(),
}));

const jobMocks = vi.hoisted(() => ({
  reset: vi.fn(),
}));

vi.mock('../api/client', () => ({
  askNl: apiMocks.askNl,
  submitQuery: apiMocks.submitQuery,
  fetchQueryReuseCandidate: apiMocks.fetchQueryReuseCandidate,
  saveQuery: vi.fn(),
  promoteToReport: vi.fn(),
  submitCorrection: vi.fn(),
  saveCampusPreference: vi.fn().mockResolvedValue(undefined),
  downloadExportCsv: vi.fn(),
  saveClarificationResolution: vi.fn(),
  saveQueryFeedback: vi.fn(),
  recordQueryReuseDecision: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../hooks/useAuth', () => ({
  useAuth: () => ({ user: null, authEnabled: false }),
}));

vi.mock('../hooks/useJobPolling', () => ({
  useJobPolling: () => ({
    job: null,
    results: null,
    isRunning: false,
    error: null,
    cancel: vi.fn(),
    reset: jobMocks.reset,
    elapsedSeconds: 0,
  }),
}));

vi.mock('../components/ToastProvider', () => ({
  useToast: () => toastMocks,
}));

vi.mock('../components/SqlPreview', () => ({
  default: ({ sql }: { sql: string }) => <pre>{sql}</pre>,
}));

vi.mock('../components/ResultsTable', () => ({ default: () => null }));
vi.mock('../components/ResultsModal', () => ({ default: () => null }));

function renderAsk() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return render(
    <QueryClientProvider client={queryClient}>
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

function rejectedTypedResponse(status: number, data: Record<string, unknown>) {
  return {
    isAxiosError: true,
    message: `Request failed with status code ${status}`,
    response: { status, data },
  };
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
  apiMocks.submitQuery.mockResolvedValue({ jobId: 'job-success' });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Ask request lifecycle', () => {
  it.each([
    [
      403,
      {
        errorType: 'policy_blocked',
        error: 'This request is blocked by reporting data policy. Please retry with aggregate reporting data.',
        route: 'blocked',
      },
    ],
    [
      503,
      {
        errorType: 'ai_provider_failure',
        error: 'The AI provider could not complete this report. Please retry.',
        route: 'ai_provider_failure',
      },
    ],
    [
      504,
      {
        errorType: 'ai_timeout',
        error: 'The AI request timed out. Please retry.',
        route: 'ai_timeout',
      },
    ],
  ])('replaces stale success with the typed Retry terminal UI after a rejected %i response', async (status, responseBody) => {
    apiMocks.askNl
      .mockResolvedValueOnce({
        sql: 'SELECT title FROM inventory.instance__t',
        generationProvenance: 'ai_built',
        provenanceLabel: 'AI-built',
      })
      .mockRejectedValueOnce(rejectedTypedResponse(status, responseBody));

    renderAsk();
    submitQuestion('Show titles');
    expect(await screen.findByRole('heading', { name: 'AI-built' })).toBeInTheDocument();

    submitQuestion('Try another report');

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/retry/i);
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'AI-built' })).not.toBeInTheDocument();
    expect(screen.queryByText('SELECT title FROM inventory.instance__t')).not.toBeInTheDocument();
    expect(jobMocks.reset).toHaveBeenCalledTimes(2);
    expect(apiMocks.askNl).toHaveBeenCalledTimes(2);
  });

  it('uses neutral default-on generation copy without presenting clarification as a prerequisite', () => {
    renderAsk();

    expect(screen.queryByText(/when the request is clear/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/needs clarification/i)).not.toBeInTheDocument();
  });

  it('renders successful AI-built reports with provenance only, without internal review details', async () => {
    apiMocks.askNl.mockResolvedValue({
      sql: 'SELECT title FROM inventory.instance__t',
      generationProvenance: 'ai_built',
      provenanceLabel: 'AI-built',
      reviewRequired: true,
      reviewNotice: {
        title: 'Internal review warning',
        message: 'The report needed a substantial automatic correction before it could run.',
      },
      assumptions: [{
        key: 'internal_assumption',
        label: 'Internal assumption',
        value: 'Fallback value',
        explanation: 'This assumption came from an automatic repair.',
        correctionExample: 'Correct the internal assumption.',
        source: 'default',
      }],
      reportDisclosures: ['The semantic checker could not verify every requested detail.'],
      semanticValidation: {
        status: 'validated',
        contractVersion: 1,
        checkedRequirements: [{ key: 'internal_check', label: 'Internal semantic requirement' }],
      },
    });

    renderAsk();
    submitQuestion('Build an uncommon report');

    expect(await screen.findByRole('heading', { name: 'AI-built' })).toBeInTheDocument();
    expect(screen.getByText('This report was generated with AI assistance.')).toBeInTheDocument();
    expect(screen.queryByText(/substantial automatic correction/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/internal assumption/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/semantic checker/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/internal semantic requirement/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/safety and preflight/i)).not.toBeInTheDocument();
  });

  it('retries the terminal failure currently selected from history', async () => {
    const terminalFailure = rejectedTypedResponse(503, {
      errorType: 'ai_provider_failure',
      error: 'The AI provider could not complete this report. Please retry.',
      route: 'ai_provider_failure',
    });
    apiMocks.askNl.mockRejectedValue(terminalFailure);

    renderAsk();
    submitQuestion('First failed report');
    expect(await screen.findByRole('alert')).toBeInTheDocument();

    submitQuestion('Second failed report');
    expect(await screen.findByRole('alert')).toBeInTheDocument();

    fireEvent.click(screen.getAllByTitle('Recent questions')[0]);
    fireEvent.click((await screen.findAllByText('First failed report'))[0]);
    fireEvent.click(screen.getAllByRole('button', { name: 'Retry' })[0]);

    await waitFor(() => {
      expect(apiMocks.askNl).toHaveBeenCalledTimes(3);
      expect(apiMocks.askNl).toHaveBeenLastCalledWith(
        'First failed report',
        'Smith College',
        true,
        null,
        true,
        null,
      );
    });
  });
});
