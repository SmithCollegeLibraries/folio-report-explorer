import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LocalDataPage from './LocalData';

const { auth, listAcrlYears, listAcrl, getBudgetYearExpenseClassOptions } = vi.hoisted(() => ({
  auth: { current: null as any },
  listAcrlYears: vi.fn(),
  listAcrl: vi.fn(),
  getBudgetYearExpenseClassOptions: vi.fn(),
}));

vi.mock('../hooks/useAuth', () => ({ useAuth: () => auth.current }));
vi.mock('../api/acrl', () => ({
  listAcrlYears,
  listAcrl,
  copyAcrlYear: vi.fn(),
  createAcrlRows: vi.fn(),
  deleteAcrlRow: vi.fn(),
  updateAcrlRow: vi.fn(),
}));
vi.mock('../api/budgetYearExpenseClass', () => ({ getBudgetYearExpenseClassOptions }));
vi.mock('../api/expenseAllocations', () => ({
  listAllocationYears: vi.fn().mockResolvedValue([]),
  listAllocations: vi.fn().mockResolvedValue({ items: [], years: [] }),
  upsertAllocations: vi.fn(),
  deleteAllocation: vi.fn(),
  copyAllocationYear: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderLocalData() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<MemoryRouter><QueryClientProvider client={queryClient}><LocalDataPage /></QueryClientProvider></MemoryRouter>);
}

describe('Local Data access', () => {
  it('shows only assigned allocation colleges to an allocation editor', async () => {
    auth.current = {
      user: { role: 'user' },
      isAdmin: false,
      authEnabled: true,
      allocationColleges: ['MH'],
      canEditAllocationsFor: () => true,
    };
    listAcrlYears.mockResolvedValue([]);
    listAcrl.mockResolvedValue({ items: [] });
    getBudgetYearExpenseClassOptions.mockResolvedValue({ prefix: 'MH', funds: [], expenseClasses: [], fiscalYears: [] });
    const user = userEvent.setup();
    renderLocalData();

    expect(screen.queryByRole('button', { name: 'ACRL Statistics' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Expense Allocations' })).not.toBeInTheDocument();
    expect(screen.getByText('Choose a college to view or manage its expense-class allocations.')).toBeInTheDocument();
    expect(screen.getByLabelText('College')).toHaveValue('');
    await user.selectOptions(screen.getByLabelText('College'), 'MH');
    await waitFor(() => expect(getBudgetYearExpenseClassOptions).toHaveBeenCalledWith('MH'));
    expect(screen.getByRole('option', { name: 'Mount Holyoke College (MH)' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Smith College (SC)' })).not.toBeInTheDocument();
    expect(listAcrlYears).not.toHaveBeenCalled();
    expect(listAcrl).not.toHaveBeenCalled();
  });

  it('shows expense allocations and keeps the ACRL question catalogue link for administrators', async () => {
    auth.current = {
      user: { role: 'admin' },
      isAdmin: true,
      authEnabled: true,
      allocationColleges: [],
      canEditAllocationsFor: () => true,
    };
    listAcrlYears.mockResolvedValue([]);
    listAcrl.mockResolvedValue({ items: [] });
    getBudgetYearExpenseClassOptions.mockResolvedValue({ prefix: 'SC', funds: [], expenseClasses: [], fiscalYears: [] });
    renderLocalData();

    expect(screen.queryByRole('button', { name: 'ACRL Statistics' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'ACRL question catalogue' })).toHaveAttribute('href', '/acrl');
    expect(screen.getByText('Choose a college to view or manage its expense-class allocations.')).toBeInTheDocument();
    const college = screen.getByLabelText('College');
    expect(college).toHaveValue('');
    expect(screen.getByRole('option', { name: 'Mount Holyoke College (MH)' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Smith College (SC)' })).toBeInTheDocument();
    expect(listAcrlYears).not.toHaveBeenCalled();
    expect(listAcrl).not.toHaveBeenCalled();
  });
});
