import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent } from '@testing-library/react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import ReportDetail from './ReportDetail';
import Reports from './Reports';

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client');

  return {
    ...actual,
    listReports: vi.fn().mockResolvedValue({
      acquisitions: [
        {
          id: 1,
          slug: 'budget-report',
          name: 'Budget Report by Material Type',
          description: 'Summarizes expenditures by material type.',
          category: 'acquisitions',
          parameterCount: 3,
          defaultLimit: 10000,
          createdBy: 'system',
          createdAt: '2026-04-01T00:00:00Z',
        },
      ],
      finance: [
        {
          id: 5,
          slug: 'fund-allocation',
          name: 'Fund Allocation by Fiscal Year',
          description: 'Allocated budget amounts per fiscal year.',
          category: 'finance',
          parameterCount: 1,
          defaultLimit: 10000,
          createdBy: 'system',
          createdAt: '2026-04-01T00:00:00Z',
        },
      ],
    }),
    deleteReport: vi.fn(),
    getReport: vi.fn(),
    runReport: vi.fn(),
    createReport: vi.fn(),
    generateReportTemplate: vi.fn(),
    convertReportFromPhp: vi.fn(),
  };
});

describe('Reports', () => {
  it('shows grouped category sections on the reports landing page', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports']}>
          <Routes>
            <Route path="/reports" element={<Reports />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Acquisitions' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Finance' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^All$/i })).not.toBeInTheDocument();
  });

  it('lets you collapse and reopen report parameters on the detail page', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    });

    const { getReport } = await import('../api/client');
    vi.mocked(getReport).mockResolvedValue({
      id: 1,
      slug: 'budget-report',
      name: 'Budget Report by Material Type',
      description: 'Summarizes expenditures by material type.',
      category: 'acquisitions',
      sqlTemplate: 'select 1',
      parameters: [
        {
          name: 'campus',
          type: 'text',
          label: 'Campus',
          required: false,
          default: '',
          resolvedDefault: '',
          placeholder: 'Choose campus',
        },
      ],
      defaultLimit: 10000,
      isActive: true,
      createdBy: 'manual',
      createdAt: '2026-04-01T00:00:00Z',
      updatedAt: '2026-04-01T00:00:00Z',
      selectOptions: {},
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports/1']}>
          <Routes>
            <Route path="/reports/:id" element={<ReportDetail />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByPlaceholderText('Choose campus')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /collapse parameters/i }));

    expect(screen.queryByPlaceholderText('Choose campus')).not.toBeInTheDocument();
    expect(screen.getByText(/parameters are hidden/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /show parameters/i }));

    expect(screen.getByPlaceholderText('Choose campus')).toBeInTheDocument();
  });
});