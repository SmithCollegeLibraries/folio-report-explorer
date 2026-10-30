import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import Ask from './Ask';

const apiMocks = vi.hoisted(() => ({
  askNl: vi.fn(),
  submitQuery: vi.fn(),
  fetchQueryReuseCandidate: vi.fn(),
  saveQuery: vi.fn(),
  promoteToReport: vi.fn(),
  recordQueryMemorySignal: vi.fn(),
}));

vi.mock('../api/client', () => ({
  askNl: apiMocks.askNl,
  submitQuery: apiMocks.submitQuery,
  fetchQueryReuseCandidate: apiMocks.fetchQueryReuseCandidate,
  saveQuery: apiMocks.saveQuery,
  promoteToReport: apiMocks.promoteToReport,
  submitCorrection: vi.fn(),
  saveCampusPreference: vi.fn().mockResolvedValue(undefined),
  downloadExportCsv: vi.fn(),
  saveClarificationResolution: vi.fn(),
  saveQueryFeedback: vi.fn(),
  replaceQueryFeedback: vi.fn(),
  recordQueryReuseDecision: vi.fn(),
  recordQueryMemorySignal: apiMocks.recordQueryMemorySignal,
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
    reset: vi.fn(),
    elapsedSeconds: 0,
  }),
}));

vi.mock('../components/ToastProvider', () => ({
  useToast: () => ({ error: vi.fn(), success: vi.fn() }),
}));
vi.mock('../components/SqlPreview', () => ({ default: ({ sql }: { sql: string }) => <pre>{sql}</pre> }));
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
      <MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <Ask />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

async function saveAskQuery() {
  const prompt = screen.getAllByPlaceholderText('Describe the report you want...')[0];
  fireEvent.change(prompt, { target: { value: 'Build a synthetic report' } });
  fireEvent.keyDown(prompt, { key: 'Enter', code: 'Enter' });
  fireEvent.click(await screen.findByRole('button', { name: 'Output SQL' }));
  await screen.findByText('SELECT 1');

  fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
  fireEvent.change(screen.getByPlaceholderText('Query name'), { target: { value: 'Synthetic saved query' } });
  const saveButtons = screen.getAllByRole('button', { name: 'Save', exact: true });
  fireEvent.click(saveButtons[saveButtons.length - 1]);

  return screen.findByRole('button', { name: 'Save as Report' });
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
  apiMocks.askNl.mockResolvedValue({ sql: 'SELECT 1', dataSource: 'folio' });
  apiMocks.submitQuery.mockResolvedValue({ jobId: 'synthetic-job' });
  apiMocks.fetchQueryReuseCandidate.mockResolvedValue({ match: null });
  apiMocks.saveQuery.mockResolvedValue({ id: 12, name: 'Synthetic saved query' });
  apiMocks.recordQueryMemorySignal.mockResolvedValue({ ok: true, signal: 'saved', count: 1 });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Ask Save as Report state', () => {
  it('keeps the button disabled as “Saved as report” after creation and prevents another request', async () => {
    apiMocks.promoteToReport.mockResolvedValue({
      data: { id: 37, slug: 'synthetic-report', name: 'Synthetic report' },
      status: 201,
    });
    renderAsk();

    fireEvent.click(await saveAskQuery());

    const button = await screen.findByRole('button', { name: /Saved as report/i });
    expect(button).toBeDisabled();
    fireEvent.click(button);

    expect(apiMocks.promoteToReport).toHaveBeenCalledTimes(1);
    expect(apiMocks.promoteToReport).toHaveBeenCalledWith(12);
  });

  it('shows that the report already exists when the promote response is 200', async () => {
    apiMocks.promoteToReport.mockResolvedValue({
      data: { id: 37, slug: 'synthetic-report', name: 'Synthetic report' },
      status: 200,
    });
    renderAsk();

    fireEvent.click(await saveAskQuery());

    expect(await screen.findByText(/report already exists/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Saved as report/i })).toBeDisabled();
  });
});
