import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ExpenseAllocationsPanel from './ExpenseAllocationsPanel';

const {
  getBudgetYearExpenseClassOptions,
  listAllocationYears,
  listAllocations,
  upsertAllocations,
  deleteAllocation,
  copyAllocationYear,
} = vi.hoisted(() => ({
  getBudgetYearExpenseClassOptions: vi.fn(),
  listAllocationYears: vi.fn(),
  listAllocations: vi.fn(),
  upsertAllocations: vi.fn(),
  deleteAllocation: vi.fn(),
  copyAllocationYear: vi.fn(),
}));

vi.mock('../api/budgetYearExpenseClass', () => ({ getBudgetYearExpenseClassOptions }));
vi.mock('../api/expenseAllocations', () => ({
  listAllocationYears,
  listAllocations,
  upsertAllocations,
  deleteAllocation,
  copyAllocationYear,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('ExpenseAllocationsPanel', () => {
  it('requires a college and scopes class choices, edits, and copy-year by that college', async () => {
    const user = userEvent.setup();
    getBudgetYearExpenseClassOptions.mockImplementation(async (unit: string) => ({
      prefix: unit,
      funds: [],
      expenseClasses: unit === 'SC'
        ? [{ code: 'SCAP', name: 'SC Books' }]
        : [{ code: 'MHBPS', name: 'MH Books' }],
      fiscalYears: [],
    }));
    listAllocationYears.mockResolvedValue([2026, 2025]);
    listAllocations.mockResolvedValue({
      items: [{ id: 1, fiscal_year: 2026, expense_class_code: 'SCAP', allocation_amount: 100 }],
      years: [2026, 2025],
    });
    upsertAllocations.mockResolvedValue({ success: true, inserted: 1, updated: 0 });
    deleteAllocation.mockResolvedValue(undefined);
    copyAllocationYear.mockResolvedValue({ success: true, copied: 1, skipped: 0, sourceYear: 2025 });

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={client}><ExpenseAllocationsPanel /></QueryClientProvider>);

    const college = screen.getByLabelText(/college/i);
    expect(screen.getByRole('button', { name: /copy previous year/i })).toBeDisabled();
    await user.selectOptions(college, 'SC');
    await waitFor(() => expect(getBudgetYearExpenseClassOptions).toHaveBeenCalledWith('SC'));

    await user.selectOptions(screen.getByLabelText(/fiscal year/i), '2026');
    await user.selectOptions(screen.getByLabelText(/expense class/i), 'SCAP');
    await user.type(screen.getByLabelText(/amount/i), '500');
    await user.click(screen.getByRole('button', { name: /add \/ update allocation/i }));
    await waitFor(() => expect(upsertAllocations).toHaveBeenCalledWith(2026, 'SC', { code: 'SCAP', amount: 500 }));

    await user.click(screen.getByRole('button', { name: /copy previous year/i }));
    await waitFor(() => expect(copyAllocationYear).toHaveBeenCalledWith(2026, 'SC'));

    await user.selectOptions(college, 'MH');
    await waitFor(() => expect(getBudgetYearExpenseClassOptions).toHaveBeenCalledWith('MH'));
    expect(screen.queryByRole('option', { name: /SC Books/ })).not.toBeInTheDocument();
    expect(screen.getByRole('option', { name: /MH Books/ })).toBeInTheDocument();
  });
});
