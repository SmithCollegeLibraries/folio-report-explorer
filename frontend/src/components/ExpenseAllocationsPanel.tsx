import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getBudgetYearExpenseClassOptions } from '../api/budgetYearExpenseClass';
import {
  copyAllocationYear,
  deleteAllocation,
  listAllocationYears,
  listAllocations,
  upsertAllocations,
} from '../api/expenseAllocations';

export default function ExpenseAllocationsPanel() {
  const queryClient = useQueryClient();
  const [prefix, setPrefix] = useState('');
  const [fiscalYear, setFiscalYear] = useState(new Date().getFullYear() + 1);
  const [expenseClassCode, setExpenseClassCode] = useState('');
  const [amount, setAmount] = useState('');
  const [bulkText, setBulkText] = useState('');
  const optionsQuery = useQuery({
    queryKey: ['budget-year-expense-class-options', prefix],
    queryFn: () => getBudgetYearExpenseClassOptions(prefix),
    enabled: prefix !== '',
  });
  const yearsQuery = useQuery({
    queryKey: ['local', 'alloc-years', prefix],
    queryFn: () => listAllocationYears(prefix),
    enabled: prefix !== '',
  });
  const allocationsQuery = useQuery({
    queryKey: ['local', 'alloc', prefix, fiscalYear],
    queryFn: () => listAllocations(fiscalYear, prefix),
    enabled: prefix !== '',
  });
  const options = optionsQuery.data?.prefix === prefix ? optionsQuery.data : undefined;
  const years = useMemo(() => Array.from(new Set([
    fiscalYear,
    new Date().getFullYear(),
    new Date().getFullYear() + 1,
    ...(yearsQuery.data || []),
  ])).sort((left, right) => right - left), [fiscalYear, yearsQuery.data]);

  const invalidateAllocations = () => {
    queryClient.invalidateQueries({ queryKey: ['local', 'alloc', prefix] });
    queryClient.invalidateQueries({ queryKey: ['local', 'alloc-years', prefix] });
  };
  const saveMutation = useMutation({
    mutationFn: ({ fiscalYear: year, prefix: college, payload }: {
      fiscalYear: number;
      prefix: string;
      payload:
        | { code: string; amount: number }
        | { rows: Array<{ expense_class_code: string; allocation_amount: number }> }
        | { pastedData: string };
    }) => upsertAllocations(year, college, payload),
    onSuccess: invalidateAllocations,
  });
  const deleteMutation = useMutation({
    mutationFn: ({ id, college }: { id: number; college: string }) => deleteAllocation(id, college),
    onSuccess: invalidateAllocations,
  });
  const copyMutation = useMutation({
    mutationFn: ({ year, college }: { year: number; college: string }) => copyAllocationYear(year, college),
    onSuccess: invalidateAllocations,
  });

  const addAllocation = () => {
    const parsedAmount = Number(amount);
    if (!prefix || !expenseClassCode || !amount || !Number.isFinite(parsedAmount) || parsedAmount < 0) {
      return;
    }
    saveMutation.mutate({ fiscalYear, prefix, payload: { code: expenseClassCode, amount: parsedAmount } });
    setExpenseClassCode('');
    setAmount('');
  };

  return (
    <section className="space-y-4 rounded-lg border bg-white p-4">
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor="allocation-college" className="mb-1 block text-sm text-gray-600">College</label>
          <select
            id="allocation-college"
            className="rounded border px-2 py-1.5"
            value={prefix}
            onChange={(event) => {
              setPrefix(event.target.value);
              setExpenseClassCode('');
              setAmount('');
            }}
          >
            <option value="">Select college</option>
            <option value="AC">Amherst College (AC)</option>
            <option value="HC">Hampshire College (HC)</option>
            <option value="MH">Mount Holyoke College (MH)</option>
            <option value="SC">Smith College (SC)</option>
            <option value="UM">UMass Amherst (UM)</option>
          </select>
        </div>

        <div>
          <label htmlFor="allocation-fiscal-year" className="mb-1 block text-sm text-gray-600">Fiscal year</label>
          <select
            id="allocation-fiscal-year"
            className="rounded border px-2 py-1.5"
            value={fiscalYear}
            onChange={(event) => setFiscalYear(Number(event.target.value))}
          >
            {years.map((year) => <option key={year} value={year}>{year}</option>)}
          </select>
        </div>

        <button
          type="button"
          className="rounded bg-folio-600 px-3 py-1.5 text-white disabled:opacity-50"
          onClick={() => copyMutation.mutate({ year: fiscalYear, college: prefix })}
          disabled={!prefix || copyMutation.isPending}
        >
          Copy Previous Year
        </button>
      </div>

      {!prefix ? (
        <p className="text-sm text-gray-500">Choose a college to view or manage its expense-class allocations.</p>
      ) : (
        <>
          {optionsQuery.isError && <p role="alert" className="text-sm text-red-600">Expense classes could not be loaded.</p>}
          {saveMutation.isError && <p role="alert" className="text-sm text-red-600">Allocation could not be saved. Choose an expense class from this college.</p>}
          <div className="overflow-auto rounded border">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 text-gray-600">
                <tr>
                  <th className="p-2 text-left">Expense Class Code</th>
                  <th className="p-2 text-right">Allocation</th>
                  <th className="p-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {(allocationsQuery.data?.items || []).map((row) => (
                  <AllocationRow
                    key={row.id}
                    code={row.expense_class_code}
                    amount={row.allocation_amount}
                    saving={saveMutation.isPending || deleteMutation.isPending}
                    onSave={(nextAmount) => saveMutation.mutate({
                      fiscalYear,
                      prefix,
                      payload: { code: row.expense_class_code, amount: nextAmount },
                    })}
                    onDelete={() => deleteMutation.mutate({ id: row.id, college: prefix })}
                  />
                ))}
              </tbody>
            </table>
          </div>

          <div className="grid grid-cols-1 gap-2 md:grid-cols-3">
            <div>
              <label htmlFor="allocation-expense-class" className="mb-1 block text-sm text-gray-600">Expense class</label>
              <select
                id="allocation-expense-class"
                className="w-full rounded border px-2 py-1.5"
                value={expenseClassCode}
                onChange={(event) => setExpenseClassCode(event.target.value)}
              >
                <option value="">Select expense class</option>
                {(options?.expenseClasses || []).map((expenseClass) => (
                  <option key={expenseClass.code} value={expenseClass.code}>
                    {expenseClass.code} — {expenseClass.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="allocation-amount" className="mb-1 block text-sm text-gray-600">Allocation amount</label>
              <input
                id="allocation-amount"
                type="number"
                min="0"
                step="0.01"
                className="w-full rounded border px-2 py-1.5"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
              />
            </div>
            <button
              type="button"
              className="self-end rounded bg-folio-600 px-3 py-1.5 text-white disabled:opacity-50"
              onClick={addAllocation}
              disabled={!expenseClassCode || !amount || saveMutation.isPending}
            >
              Add / Update Allocation
            </button>
          </div>

          <div className="space-y-2">
            <label htmlFor="allocation-bulk-data" className="block text-sm text-gray-600">Bulk import pasted data</label>
            <textarea
              id="allocation-bulk-data"
              className="h-36 w-full rounded border px-2 py-2 font-mono text-xs"
              value={bulkText}
              onChange={(event) => setBulkText(event.target.value)}
              placeholder="Paste rows from spreadsheet"
            />
            <button
              type="button"
              className="rounded bg-folio-600 px-3 py-1.5 text-white disabled:opacity-50"
              onClick={() => {
                saveMutation.mutate({ fiscalYear, prefix, payload: { pastedData: bulkText } });
                setBulkText('');
              }}
              disabled={!bulkText.trim() || saveMutation.isPending}
            >
              Import Bulk Data
            </button>
          </div>
        </>
      )}
    </section>
  );
}

function AllocationRow({
  code,
  amount,
  onSave,
  onDelete,
  saving,
}: {
  code: string;
  amount: number;
  onSave: (amount: number) => void;
  onDelete: () => void;
  saving: boolean;
}) {
  const [value, setValue] = useState(String(amount));

  return (
    <tr className="border-t">
      <td className="p-2">{code}</td>
      <td className="p-2">
        <input
          aria-label={'Allocation for ' + code}
          className="w-full rounded border px-2 py-1 text-right"
          value={value}
          onChange={(event) => setValue(event.target.value)}
        />
      </td>
      <td className="space-x-2 p-2 text-right">
        <button
          type="button"
          className="rounded bg-folio-600 px-2 py-1 text-white disabled:opacity-50"
          onClick={() => onSave(Number(value || 0))}
          disabled={saving}
        >
          Save
        </button>
        <button type="button" className="rounded border px-2 py-1" onClick={onDelete} disabled={saving}>
          Delete
        </button>
      </td>
    </tr>
  );
}
