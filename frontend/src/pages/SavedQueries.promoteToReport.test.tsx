import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { checkJobStatus, listSaved, promoteToReport } from '../api/client';
import SavedQueries from './SavedQueries';

vi.mock('../api/client', () => ({
  checkJobStatus: vi.fn(),
  deleteSaved: vi.fn(),
  downloadExportCsv: vi.fn(),
  listSaved: vi.fn(),
  promoteToReport: vi.fn(),
  refreshDashboardCard: vi.fn(),
  togglePin: vi.fn(),
}));

const savedQuery = {
  id: 12,
  name: 'Synthetic saved query',
  description: 'Synthetic test fixture',
  generated_sql: 'SELECT 1',
  source: 'builder' as const,
  is_pinned: false,
  is_global: false,
  last_job_id: null,
  created_at: '2026-10-30T12:00:00Z',
  updated_at: '2026-10-30T12:00:00Z',
};

function renderSavedQueries() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <SavedQueries />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(listSaved).mockResolvedValue([savedQuery]);
  vi.mocked(checkJobStatus).mockResolvedValue({} as Awaited<ReturnType<typeof checkJobStatus>>);
});

afterEach(cleanup);

describe('SavedQueries Save as Report state', () => {
  it('keeps the saved-query button disabled as “Saved as report” after creation and prevents another request', async () => {
    vi.mocked(promoteToReport).mockResolvedValue({
      data: { id: 37, slug: 'synthetic-report', name: 'Synthetic report' },
      status: 201,
    });
    renderSavedQueries();

    fireEvent.click(await screen.findByTitle('Save as Report'));

    const button = await screen.findByRole('button', { name: /Saved as report/i });
    expect(button).toBeDisabled();
    fireEvent.click(button);

    expect(promoteToReport).toHaveBeenCalledTimes(1);
    expect(promoteToReport).toHaveBeenCalledWith(savedQuery.id);
  });

  it('shows that the report already exists when the promote response is 200', async () => {
    vi.mocked(promoteToReport).mockResolvedValue({
      data: { id: 37, slug: 'synthetic-report', name: 'Synthetic report' },
      status: 200,
    });
    renderSavedQueries();

    fireEvent.click(await screen.findByTitle('Save as Report'));

    expect(await screen.findByText(/report already exists/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Saved as report/i })).toBeDisabled();
  });
});
