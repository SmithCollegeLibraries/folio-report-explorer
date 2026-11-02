import { useId } from 'react';
import { useVisualizationHistory } from '../../hooks/useVisualizationHistory';
import { errorMessage } from '../../utils/errorMessage';
import { amount } from './ChartCard';

const fields = [
  { key: 'fiscalYearEnd', label: 'Fiscal year', type: 'number', min: 1990, max: 2100, step: '1' },
  { key: 'totalAmount', label: 'Total', type: 'number', min: 0, max: 999999999999.99, step: '0.01' },
  { key: 'electronicAmount', label: 'Electronic', type: 'number', min: 0, max: 999999999999.99, step: '0.01' },
  { key: 'physicalAmount', label: 'Physical', type: 'number', min: 0, max: 999999999999.99, step: '0.01' },
  { key: 'note', label: 'Note', type: 'text' },
] as const;

export function HistoryPanel({ unitCode, currentYear }: { unitCode: string; currentYear: number | null }) {
  const state = useVisualizationHistory(unitCode, currentYear);
  const id = useId();
  const draft = state.draft;
  return <section aria-labelledby={`${id}-title`} className="mt-8 bg-white border border-gray-200 rounded-lg p-4 sm:p-6">
    <div className="flex flex-wrap gap-3 items-center justify-between">
      <div><h2 id={`${id}-title`} className="text-lg font-semibold">Historical figures</h2>
        <p className="text-sm text-gray-600">Manage stored figures for {unitCode}. Stored figures take precedence for that year.</p></div>
      <button type="button" onClick={() => state.open()} className="rounded-md bg-folio-600 px-4 py-2 text-sm font-medium text-white hover:bg-folio-700 disabled:opacity-50">Add historical figure</button>
    </div>
    {state.history.isPending && <p role="status" className="mt-4">Loading historical figures…</p>}
    {state.history.isError && <div role="alert" className="mt-4 text-red-700">{errorMessage(state.history.error)}
      <button type="button" onClick={() => void state.history.refetch()} className="ml-3 underline">Retry history</button></div>}
    {state.remove.isError && <p role="alert" className="text-red-700">{errorMessage(state.remove.error)}</p>}
    {draft && <form className="my-5 p-4 border rounded bg-gray-50" onSubmit={(event) => { event.preventDefault(); state.submit(); }}>
      <h3 className="font-semibold mb-3">{state.editing ? 'Edit historical figure' : 'New historical figure'}</h3>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div><label htmlFor={`${id}-kind`} className="block text-sm font-medium mb-1">Kind</label>
          <select id={`${id}-kind`} className={`border rounded px-3 py-2 w-full ${state.editing ? 'bg-gray-100 cursor-not-allowed' : ''}`} value={draft.kind} aria-readonly={state.editing}
            onChange={(event) => state.update('kind', event.target.value)} aria-invalid={Boolean(state.errors.kind)}
            aria-describedby={state.errors.kind ? `${id}-kind-error` : undefined}>
            <option value="purchases">purchases</option><option value="budget">budget</option>
          </select>{state.errors.kind && <p id={`${id}-kind-error`} className="text-sm text-red-700">{state.errors.kind}</p>}</div>
        {fields.map((field) => {
          if (draft.kind === 'budget' && (field.key === 'electronicAmount' || field.key === 'physicalAmount')) return null;
          const fieldId = `${id}-${field.key}`;
          return <div key={field.key}><label htmlFor={fieldId} className="block text-sm font-medium mb-1">{field.label}</label>
            <input id={fieldId} type={field.type} value={draft[field.key]}
              {...(field.type === 'number' ? { min: field.min, max: field.max, step: field.step, required: true } : { maxLength: 255 })}
              onChange={(event) => state.update(field.key, event.target.value)}
              aria-readonly={state.editing && field.key === 'fiscalYearEnd'}
              className={`border rounded px-3 py-2 w-full ${state.editing && field.key === 'fiscalYearEnd' ? 'bg-gray-100 cursor-not-allowed' : ''}`}
              aria-invalid={Boolean(state.errors[field.key])} aria-describedby={state.errors[field.key] ? `${fieldId}-error` : undefined} />
            {state.errors[field.key] && <p id={`${fieldId}-error`} className="text-sm text-red-700">{state.errors[field.key]}</p>}
          </div>;
        })}
      </div>
      {state.save.isError && Object.keys(state.errors).length === 0 && <p role="alert" className="mt-3 text-red-700">{errorMessage(state.save.error)}</p>}
      <div className="flex gap-3 mt-4"><button type="submit" disabled={state.save.isPending} className="rounded-md bg-folio-600 px-4 py-2 text-sm font-medium text-white hover:bg-folio-700 disabled:opacity-50">{state.save.isPending ? 'Saving…' : 'Save'}</button>
        <button type="button" disabled={state.save.isPending} onClick={state.close} className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-100 disabled:opacity-50">Cancel</button></div>
    </form>}
    <div className="overflow-x-auto mt-4"><table className="w-full text-sm [&_th]:p-2 [&_th]:text-left [&_td]:p-2 [&_tr]:border-b">
      <caption className="sr-only">Historical figures for {unitCode}</caption>
      <thead><tr>{['Fiscal year', 'Kind', 'Total', 'Electronic', 'Physical', 'Note', 'Updated by', 'Updated at', 'Actions'].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
      <tbody>{state.history.data?.map((row) => <tr key={row.id}>
        <th scope="row">{row.fiscalYearEnd}</th><td>{row.kind}</td><td>{amount(row.totalAmount)}</td>
        <td>{row.electronicAmount === null ? '—' : amount(row.electronicAmount)}</td>
        <td>{row.physicalAmount === null ? '—' : amount(row.physicalAmount)}</td><td>{row.note}</td>
        <td>{row.updatedBy ?? '—'}</td><td className="whitespace-nowrap">{row.updatedAt}</td>
        <td><div className="flex gap-3"><button type="button" onClick={() => state.open(row)} className="text-folio-700 underline">Edit</button>
          <button type="button" disabled={state.remove.isPending} onClick={() => state.confirmDelete(row)} className="text-red-700 underline">Delete</button></div></td>
      </tr>)}</tbody>
    </table></div>
    {state.history.data?.length === 0 && <p className="mt-3 text-sm text-gray-600">No stored historical figures for this unit.</p>}
  </section>;
}
