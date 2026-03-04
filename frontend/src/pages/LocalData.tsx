import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  copyAcrlYear,
  copyAllocationYear,
  createAcrlRows,
  deleteAcrlRow,
  deleteAllocation,
  listAcrl,
  listAcrlYears,
  listAllocationYears,
  listAllocations,
  updateAcrlRow,
  upsertAllocations,
} from '../api/client';

export default function LocalDataPage() {
  const qc = useQueryClient();
  const [tab, setTab] = useState<'acrl' | 'alloc'>('acrl');

  const [acrlYear, setAcrlYear] = useState<number | undefined>(undefined);
  const [copyFromYear, setCopyFromYear] = useState<number | ''>('');
  const [copyToYear, setCopyToYear] = useState<number | ''>('');

  const [allocYear, setAllocYear] = useState<number>(new Date().getFullYear() + 1);
  const [newCode, setNewCode] = useState('');
  const [newAmount, setNewAmount] = useState('');
  const [bulkText, setBulkText] = useState('');

  const acrlYearsQ = useQuery({ queryKey: ['local', 'acrl-years'], queryFn: listAcrlYears });
  const acrlQ = useQuery({
    queryKey: ['local', 'acrl', acrlYear ?? 'all'],
    queryFn: () => listAcrl(acrlYear),
  });

  const allocYearsQ = useQuery({ queryKey: ['local', 'alloc-years'], queryFn: listAllocationYears });
  const allocQ = useQuery({
    queryKey: ['local', 'alloc', allocYear],
    queryFn: () => listAllocations(allocYear),
  });

  const saveAcrlMut = useMutation({
    mutationFn: ({ id, patch }: { id: number; patch: { value?: number | null; notes?: string | null } }) => updateAcrlRow(id, patch),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['local', 'acrl'] }),
  });

  const addAcrlMut = useMutation({
    mutationFn: createAcrlRows,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['local', 'acrl'] });
      qc.invalidateQueries({ queryKey: ['local', 'acrl-years'] });
    },
  });

  const deleteAcrlMut = useMutation({
    mutationFn: deleteAcrlRow,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['local', 'acrl'] }),
  });

  const copyAcrlMut = useMutation({
    mutationFn: ({ fromYear, toYear }: { fromYear: number; toYear: number }) => copyAcrlYear(fromYear, toYear, false),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['local', 'acrl'] });
      qc.invalidateQueries({ queryKey: ['local', 'acrl-years'] });
    },
  });

  const upsertAllocMut = useMutation({
    mutationFn: ({ fiscalYear, payload }: {
      fiscalYear: number;
      payload:
        | { code: string; amount: number }
        | { rows: Array<{ expense_class_code: string; allocation_amount: number }> }
        | { pastedData: string };
    }) => upsertAllocations(fiscalYear, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['local', 'alloc', allocYear] });
      qc.invalidateQueries({ queryKey: ['local', 'alloc-years'] });
    },
  });

  const deleteAllocMut = useMutation({
    mutationFn: deleteAllocation,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['local', 'alloc', allocYear] }),
  });

  const copyAllocMut = useMutation({
    mutationFn: copyAllocationYear,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['local', 'alloc', allocYear] });
      qc.invalidateQueries({ queryKey: ['local', 'alloc-years'] });
    },
  });

  const acrlYears = useMemo(() => acrlYearsQ.data || [], [acrlYearsQ.data]);
  const allocYears = useMemo(() => allocYearsQ.data || [], [allocYearsQ.data]);

  return (
    <div className="max-w-screen-2xl mx-auto p-4 sm:p-6 space-y-4">
      <h2 className="text-xl font-semibold">Local Data</h2>
      <p className="text-sm text-gray-500">Manage supplementary tables used for institutional reporting.</p>

      <div className="flex gap-2">
        <button
          className={`px-3 py-1.5 rounded border ${tab === 'acrl' ? 'bg-folio-600 text-white border-folio-600' : 'bg-white text-gray-700 border-gray-300'}`}
          onClick={() => setTab('acrl')}
        >
          ACRL Statistics
        </button>
        <button
          className={`px-3 py-1.5 rounded border ${tab === 'alloc' ? 'bg-folio-600 text-white border-folio-600' : 'bg-white text-gray-700 border-gray-300'}`}
          onClick={() => setTab('alloc')}
        >
          Expense Allocations
        </button>
      </div>

      {tab === 'acrl' && (
        <section className="bg-white border rounded-lg p-4 space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
            <label className="text-sm text-gray-600">Year</label>
            <select
              className="border rounded px-2 py-1.5"
              value={acrlYear ?? ''}
              onChange={(e) => setAcrlYear(e.target.value ? Number(e.target.value) : undefined)}
            >
              <option value="">All years</option>
              {acrlYears.map((y) => <option key={y} value={y}>{y}</option>)}
            </select>

            <div className="flex items-center gap-2 sm:ml-auto flex-wrap">
              <select className="border rounded px-2 py-1.5" value={copyFromYear} onChange={(e) => setCopyFromYear(e.target.value ? Number(e.target.value) : '')}>
                <option value="">From year</option>
                {acrlYears.map((y) => <option key={y} value={y}>{y}</option>)}
              </select>
              <input className="border rounded px-2 py-1.5 w-24" placeholder="To year" value={copyToYear} onChange={(e) => setCopyToYear(e.target.value ? Number(e.target.value) : '')} />
              <button
                className="px-3 py-1.5 rounded bg-folio-600 text-white disabled:opacity-50"
                disabled={!copyFromYear || !copyToYear || copyAcrlMut.isPending}
                onClick={() => copyAcrlMut.mutate({ fromYear: Number(copyFromYear), toYear: Number(copyToYear) })}
              >
                Copy Year
              </button>
            </div>
          </div>

          <div className="overflow-auto border rounded">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 text-gray-600">
                <tr>
                  <th className="text-left p-2">Category</th>
                  <th className="text-left p-2">Subcategory</th>
                  <th className="text-left p-2">Year</th>
                  <th className="text-right p-2">Value</th>
                  <th className="text-left p-2">Notes</th>
                  <th className="text-right p-2">Actions</th>
                </tr>
              </thead>
              <tbody>
                {(acrlQ.data?.items || []).map((row) => (
                  <AcrlRow
                    key={row.id}
                    row={row}
                    onSave={(patch) => saveAcrlMut.mutate({ id: row.id, patch })}
                    onDelete={() => deleteAcrlMut.mutate(row.id)}
                    saving={saveAcrlMut.isPending || deleteAcrlMut.isPending}
                  />
                ))}
              </tbody>
            </table>
          </div>

          <AddAcrlRow
            defaultYear={acrlYear || (acrlYears[0] || new Date().getFullYear())}
            onAdd={(row) => addAcrlMut.mutate([row])}
            saving={addAcrlMut.isPending}
          />
        </section>
      )}

      {tab === 'alloc' && (
        <section className="bg-white border rounded-lg p-4 space-y-4">
          <div className="flex items-center gap-3 flex-wrap">
            <label className="text-sm text-gray-600">Fiscal year</label>
            <select className="border rounded px-2 py-1.5" value={allocYear} onChange={(e) => setAllocYear(Number(e.target.value))}>
              {allocYears.map((y) => <option key={y} value={y}>{y}</option>)}
              {!allocYears.includes(allocYear) && <option value={allocYear}>{allocYear}</option>}
            </select>
            <button
              className="px-3 py-1.5 rounded bg-folio-600 text-white disabled:opacity-50"
              onClick={() => copyAllocMut.mutate(allocYear)}
              disabled={copyAllocMut.isPending}
            >
              Copy Previous Year
            </button>
          </div>

          <div className="overflow-auto border rounded">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 text-gray-600">
                <tr>
                  <th className="text-left p-2">Expense Class Code</th>
                  <th className="text-right p-2">Allocation</th>
                  <th className="text-right p-2">Actions</th>
                </tr>
              </thead>
              <tbody>
                {(allocQ.data?.items || []).map((row) => (
                  <AllocRow
                    key={row.id}
                    code={row.expense_class_code}
                    amount={row.allocation_amount}
                    onSave={(amount) => upsertAllocMut.mutate({ fiscalYear: allocYear, payload: { code: row.expense_class_code, amount } })}
                    onDelete={() => deleteAllocMut.mutate(row.id)}
                    saving={upsertAllocMut.isPending || deleteAllocMut.isPending}
                  />
                ))}
              </tbody>
            </table>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-2">
            <input className="border rounded px-2 py-1.5" placeholder="Code" value={newCode} onChange={(e) => setNewCode(e.target.value.toUpperCase())} />
            <input className="border rounded px-2 py-1.5" placeholder="Amount" value={newAmount} onChange={(e) => setNewAmount(e.target.value)} />
            <button
              className="px-3 py-1.5 rounded bg-folio-600 text-white disabled:opacity-50"
              onClick={() => {
                upsertAllocMut.mutate({ fiscalYear: allocYear, payload: { code: newCode, amount: Number(newAmount || 0) } });
                setNewCode('');
                setNewAmount('');
              }}
              disabled={!newCode || !newAmount || upsertAllocMut.isPending}
            >
              Add / Update Allocation
            </button>
          </div>

          <div className="space-y-2">
            <label className="text-sm text-gray-600">Bulk import pasted data</label>
            <textarea
              className="w-full border rounded px-2 py-2 h-36 font-mono text-xs"
              value={bulkText}
              onChange={(e) => setBulkText(e.target.value)}
              placeholder="Paste rows from spreadsheet"
            />
            <button
              className="px-3 py-1.5 rounded bg-folio-600 text-white disabled:opacity-50"
              onClick={() => {
                upsertAllocMut.mutate({ fiscalYear: allocYear, payload: { pastedData: bulkText } });
                setBulkText('');
              }}
              disabled={!bulkText.trim() || upsertAllocMut.isPending}
            >
              Import Bulk Data
            </button>
          </div>
        </section>
      )}
    </div>
  );
}

function AcrlRow({
  row,
  onSave,
  onDelete,
  saving,
}: {
  row: { category: string; subcategory: string; year: number; value: number | null; notes: string | null };
  onSave: (patch: { value?: number | null; notes?: string | null }) => void;
  onDelete: () => void;
  saving: boolean;
}) {
  const [value, setValue] = useState(row.value?.toString() ?? '');
  const [notes, setNotes] = useState(row.notes ?? '');

  return (
    <tr className="border-t">
      <td className="p-2">{row.category}</td>
      <td className="p-2">{row.subcategory}</td>
      <td className="p-2">{row.year}</td>
      <td className="p-2">
        <input className="w-full border rounded px-2 py-1 text-right" value={value} onChange={(e) => setValue(e.target.value)} />
      </td>
      <td className="p-2">
        <input className="w-full border rounded px-2 py-1" value={notes} onChange={(e) => setNotes(e.target.value)} />
      </td>
      <td className="p-2 text-right space-x-2">
        <button
          className="px-2 py-1 rounded bg-folio-600 text-white disabled:opacity-50"
          onClick={() => onSave({ value: value === '' ? null : Number(value), notes: notes || null })}
          disabled={saving}
        >
          Save
        </button>
        <button className="px-2 py-1 rounded border" onClick={onDelete} disabled={saving}>Delete</button>
      </td>
    </tr>
  );
}

function AddAcrlRow({
  defaultYear,
  onAdd,
  saving,
}: {
  defaultYear: number;
  onAdd: (row: { category: string; subcategory: string; year: number; value: number | null; notes: string | null }) => void;
  saving: boolean;
}) {
  const [category, setCategory] = useState('');
  const [subcategory, setSubcategory] = useState('');
  const [year, setYear] = useState(defaultYear);
  const [value, setValue] = useState('');
  const [notes, setNotes] = useState('');

  return (
    <div className="grid grid-cols-1 md:grid-cols-6 gap-2 border-t pt-3">
      <input className="border rounded px-2 py-1.5" placeholder="Category" value={category} onChange={(e) => setCategory(e.target.value)} />
      <input className="border rounded px-2 py-1.5" placeholder="Subcategory" value={subcategory} onChange={(e) => setSubcategory(e.target.value)} />
      <input className="border rounded px-2 py-1.5" placeholder="Year" value={year} onChange={(e) => setYear(Number(e.target.value || defaultYear))} />
      <input className="border rounded px-2 py-1.5" placeholder="Value" value={value} onChange={(e) => setValue(e.target.value)} />
      <input className="border rounded px-2 py-1.5" placeholder="Notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
      <button
        className="px-3 py-1.5 rounded bg-folio-600 text-white disabled:opacity-50"
        onClick={() => {
          onAdd({ category, subcategory, year, value: value === '' ? null : Number(value), notes: notes || null });
          setCategory('');
          setSubcategory('');
          setValue('');
          setNotes('');
        }}
        disabled={!category || !subcategory || !year || saving}
      >
        Add Row
      </button>
    </div>
  );
}

function AllocRow({
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
        <input className="w-full border rounded px-2 py-1 text-right" value={value} onChange={(e) => setValue(e.target.value)} />
      </td>
      <td className="p-2 text-right space-x-2">
        <button className="px-2 py-1 rounded bg-folio-600 text-white disabled:opacity-50" onClick={() => onSave(Number(value || 0))} disabled={saving}>Save</button>
        <button className="px-2 py-1 rounded border" onClick={onDelete} disabled={saving}>Delete</button>
      </td>
    </tr>
  );
}
