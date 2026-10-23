import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BudgetYearExpenseClassParameters from './BudgetYearExpenseClassParameters';
import type { ReportParam } from '../types';

const optionsByUnit = {
  SC: {
    prefix: 'SC',
    funds: [
      { id: 'sc-fund-one', code: 'SCBPS', name: 'Book Fund', defaultSelected: true },
      { id: 'sc-fund-two', code: 'SCBIS', name: 'E-Book Fund', defaultSelected: true },
    ],
    expenseClasses: [{ code: 'SCAP', name: 'SC Books' }],
    fiscalYears: [{ year: 2026, code: 'SCFY2026', startDate: '2025-07-02', endDate: '2026-06-30' }],
  },
  MH: {
    prefix: 'MH',
    funds: [{ id: 'mh-fund-one', code: 'MHBPS', name: 'Book Fund', defaultSelected: false }],
    expenseClasses: [{ code: 'MHBPS', name: 'MH Books' }],
    fiscalYears: [{ year: 2026, code: 'MHFY2026', startDate: '2025-07-01', endDate: '2026-06-30' }],
  },
};

vi.mock('../api/budgetYearExpenseClass', () => ({
  getBudgetYearExpenseClassOptions: vi.fn(async (unit: keyof typeof optionsByUnit) => optionsByUnit[unit]),
}));

afterEach(cleanup);

const parameters: ReportParam[] = [
  { name: 'acqUnit', type: 'select', label: 'Acquisitions unit', required: true, default: '', resolvedDefault: '' },
  { name: 'fiscalYear', type: 'number', label: 'Fiscal year', required: true, default: '', resolvedDefault: '' },
  {
    name: 'startDate',
    type: 'date',
    label: 'Start date',
    required: false,
    default: '',
    resolvedDefault: '',
    description: 'Optional inclusive payment-date lower bound.',
  },
  {
    name: 'endDate',
    type: 'date',
    label: 'End date',
    required: false,
    default: '',
    resolvedDefault: '',
    description: 'Optional inclusive payment-date upper bound.',
  },
  {
    name: 'funds',
    type: 'multiselect',
    label: 'Funds',
    required: true,
    default: 'sc-fund-one,sc-fund-two',
    resolvedDefault: 'sc-fund-one,sc-fund-two',
    placeholder: 'Search funds',
  },
];

function renderParameters() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  function Harness() {
    const [values, setValues] = useState<Record<string, string>>({
      acqUnit: '',
      fiscalYear: '',
      startDate: '',
      endDate: '',
      funds: 'sc-fund-one,sc-fund-two',
    });
    return (
      <BudgetYearExpenseClassParameters
        parameters={parameters}
        values={values}
        selectOptions={{ acqUnit: [{ value: 'SC', label: 'SC' }, { value: 'MH', label: 'MH' }] }}
        serverFieldErrors={{}}
        onChange={(name, value) => setValues((previous) => ({ ...previous, [name]: value }))}
      />
    );
  }
  return render(<QueryClientProvider client={client}><Harness /></QueryClientProvider>);
}

describe('BudgetYearExpenseClassParameters', () => {
  it('loads the selected unit fiscal year and clears Smith funds for another college', async () => {
    const user = userEvent.setup();
    renderParameters();

    await user.selectOptions(screen.getByLabelText(/Acquisitions unit/), 'SC');
    await waitFor(() => expect(screen.getByLabelText(/Fiscal year/)).toHaveValue(2026));
    const startDate = screen.getByLabelText(/Start date/);
    const endDate = screen.getByLabelText(/End date/);
    expect(startDate).toHaveValue('');
    expect(endDate).toHaveValue('');
    expect(startDate).not.toBeRequired();
    expect(endDate).not.toBeRequired();
    expect(startDate).not.toHaveAttribute('min');
    expect(startDate).not.toHaveAttribute('max');
    expect(endDate).not.toHaveAttribute('min');
    expect(endDate).not.toHaveAttribute('max');
    expect(screen.getByText('Optional inclusive payment-date lower bound.')).toBeInTheDocument();

    await user.type(startDate, '2025-07-01');
    expect(startDate).toHaveValue('2025-07-01');
    await user.clear(startDate);
    expect(startDate).toHaveValue('');
    expect(screen.getByRole('button', { name: '2 funds selected' })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText(/Acquisitions unit/), 'MH');
    await waitFor(() => expect(screen.getByRole('button', { name: /select funds/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /select funds/i }));
    expect(screen.getByRole('checkbox', { name: /MHBPS/ })).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: /SCBPS/ })).not.toBeInTheDocument();
  });
});
