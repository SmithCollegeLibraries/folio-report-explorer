import { Plus, Trash2, ArrowUp, ArrowDown, GripVertical } from 'lucide-react';
import type { SortSpec, ColumnDef } from '../types';

interface Props {
  tableColumns: Record<string, ColumnDef[]>;
  orderBy: SortSpec[];
  onOrderByChange: (specs: SortSpec[]) => void;
}

let nextSortId = 1;

export default function SortPanel({ tableColumns, orderBy, onOrderByChange }: Props) {
  const tables = Object.keys(tableColumns);

  const addSort = () => {
    if (tables.length === 0) return;
    const firstTable = tables[0];
    const firstCol = tableColumns[firstTable]?.[0]?.name ?? '';
    onOrderByChange([
      ...orderBy,
      { id: `s${nextSortId++}`, table: firstTable, column: firstCol, dir: 'ASC' },
    ]);
  };

  const removeSort = (id: string) => {
    onOrderByChange(orderBy.filter((s) => s.id !== id));
  };

  const updateSort = (id: string, patch: Partial<SortSpec>) => {
    onOrderByChange(
      orderBy.map((s) => {
        if (s.id !== id) return s;
        const updated = { ...s, ...patch };
        // If table changed, reset column to first available
        if (patch.table && patch.table !== s.table) {
          const cols = tableColumns[patch.table];
          updated.column = cols?.[0]?.name ?? '';
        }
        return updated;
      })
    );
  };

  const toggleDirection = (id: string) => {
    onOrderByChange(
      orderBy.map((s) =>
        s.id === id ? { ...s, dir: s.dir === 'ASC' ? 'DESC' : 'ASC' } : s
      )
    );
  };

  const moveSort = (index: number, dir: -1 | 1) => {
    const target = index + dir;
    if (target < 0 || target >= orderBy.length) return;
    const next = [...orderBy];
    [next[index], next[target]] = [next[target], next[index]];
    onOrderByChange(next);
  };

  if (tables.length === 0) {
    return (
      <div className="p-6 text-center text-gray-500 text-sm">
        Select at least one table and some columns first.
      </div>
    );
  }

  return (
    <div className="p-4 space-y-3">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">ORDER BY</h3>
        <button
          onClick={addSort}
          className="flex items-center gap-1 text-xs text-folio-600 hover:text-folio-700 font-medium"
        >
          <Plus size={14} /> Add Sort
        </button>
      </div>

      {orderBy.length === 0 && (
        <p className="text-xs text-gray-400">No sort rules yet. Click "Add Sort" to begin.</p>
      )}

      <div className="space-y-2">
        {orderBy.map((spec, idx) => (
          <div
            key={spec.id}
            className="flex items-center gap-2 bg-gray-50 rounded-lg p-2 border border-gray-200"
          >
            {/* Priority controls */}
            <div className="flex flex-col gap-0.5">
              <button
                onClick={() => moveSort(idx, -1)}
                disabled={idx === 0}
                className="text-gray-400 hover:text-gray-600 disabled:opacity-30"
                title="Move up"
              >
                <ArrowUp size={12} />
              </button>
              <GripVertical size={12} className="text-gray-300" />
              <button
                onClick={() => moveSort(idx, 1)}
                disabled={idx === orderBy.length - 1}
                className="text-gray-400 hover:text-gray-600 disabled:opacity-30"
                title="Move down"
              >
                <ArrowDown size={12} />
              </button>
            </div>

            {/* Priority badge */}
            <span className="text-xs text-gray-400 font-mono w-4 text-center">{idx + 1}</span>

            {/* Table select */}
            <select
              value={spec.table}
              onChange={(e) => updateSort(spec.id, { table: e.target.value })}
              className="flex-1 min-w-0 text-xs border rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-folio-400"
            >
              {tables.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>

            {/* Column select */}
            <select
              value={spec.column}
              onChange={(e) => updateSort(spec.id, { column: e.target.value })}
              className="flex-1 min-w-0 text-xs border rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-folio-400"
            >
              {(tableColumns[spec.table] ?? []).map((c) => (
                <option key={c.name} value={c.name}>
                  {c.name}
                </option>
              ))}
            </select>

            {/* Direction toggle */}
            <button
              onClick={() => toggleDirection(spec.id)}
              className={`flex items-center gap-0.5 text-xs font-medium px-2 py-1.5 rounded border ${
                spec.dir === 'ASC'
                  ? 'bg-blue-50 text-blue-700 border-blue-200'
                  : 'bg-orange-50 text-orange-700 border-orange-200'
              }`}
              title={spec.dir === 'ASC' ? 'Ascending' : 'Descending'}
            >
              {spec.dir === 'ASC' ? <ArrowUp size={12} /> : <ArrowDown size={12} />}
              {spec.dir}
            </button>

            {/* Remove */}
            <button
              onClick={() => removeSort(spec.id)}
              className="text-gray-400 hover:text-red-500"
              title="Remove"
            >
              <Trash2 size={14} />
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
