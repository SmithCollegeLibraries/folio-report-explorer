import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import type { FilterCondition, ColumnDef } from '../types';

interface Props {
  tableColumns: Record<string, ColumnDef[]>;
  filters: FilterCondition[];
  onFiltersChange: (filters: FilterCondition[]) => void;
}

const OPERATORS = [
  { value: '=', label: '= equals' },
  { value: '!=', label: '!= not equal' },
  { value: '>', label: '> greater than' },
  { value: '<', label: '< less than' },
  { value: '>=', label: '>= greater or equal' },
  { value: '<=', label: '<= less or equal' },
  { value: 'LIKE', label: 'LIKE (pattern)' },
  { value: 'NOT LIKE', label: 'NOT LIKE' },
  { value: 'ILIKE', label: 'ILIKE (case-insensitive)' },
  { value: 'IN', label: 'IN (comma-separated)' },
  { value: 'BETWEEN', label: 'BETWEEN' },
  { value: 'IS NULL', label: 'IS NULL' },
  { value: 'IS NOT NULL', label: 'IS NOT NULL' },
];

const NO_VALUE_OPS = new Set(['IS NULL', 'IS NOT NULL']);

let nextId = 1;

export default function FilterPanel({
  tableColumns,
  filters,
  onFiltersChange,
}: Props) {
  const tables = Object.keys(tableColumns || {});
  const [activeTable, setActiveTable] = useState(tables[0] || '');

  const addFilter = () => {
    const table = activeTable || tables[0] || '';
    const cols = tableColumns[table] || [];
    const column = cols[0]?.name || '';
    onFiltersChange([
      ...filters,
      { id: `f${nextId++}`, table, column, op: '=', value: '' },
    ]);
  };

  const updateFilter = (id: string, updates: Partial<FilterCondition>) => {
    onFiltersChange(
      filters.map((f) => (f.id === id ? { ...f, ...updates } : f)),
    );
  };

  const removeFilter = (id: string) => {
    onFiltersChange(filters.filter((f) => f.id !== id));
  };

  if (tables.length === 0) {
    return (
      <div className="p-6 text-center text-gray-500 text-sm">
        Select at least one table first.
      </div>
    );
  }

  return (
    <div className="p-4 space-y-3">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">WHERE Conditions</h3>
        <button
          onClick={addFilter}
          className="flex items-center gap-1 text-xs text-folio-600 hover:text-folio-700 font-medium"
        >
          <Plus size={14} /> Add Filter
        </button>
      </div>

      {filters.length > 0 && (
        <p className="text-xs text-gray-400">All conditions are combined with AND.</p>
      )}

      {filters.length === 0 && (
        <p className="text-xs text-gray-400">No filters added. Click "Add Filter" to begin.</p>
      )}

      <div className="space-y-2">
        {filters.map((f, idx) => {
          const cols = tableColumns[f.table] || [];
          const isBetween = f.op === 'BETWEEN';
          const noValue = NO_VALUE_OPS.has(f.op);

          return (
            <div
              key={f.id}
              className="bg-gray-50 rounded-lg border border-gray-200 p-3"
            >
              {/* AND label for 2nd+ conditions */}
              {idx > 0 && (
                <div className="text-xs text-gray-400 font-medium mb-2">AND</div>
              )}

              <div className="grid grid-cols-[1fr_1fr_auto] gap-2 items-start">
                {/* Table */}
                <div>
                  <label className="block text-xs text-gray-500 mb-1">Table</label>
                  <select
                    value={f.table}
                    onChange={(e) => {
                      const newTable = e.target.value;
                      setActiveTable(newTable);
                      const newCols = tableColumns[newTable] || [];
                      updateFilter(f.id, {
                        table: newTable,
                        column: newCols[0]?.name || '',
                      });
                    }}
                    className="w-full text-xs border rounded-md px-2 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-folio-400"
                  >
                    {tables.map((t) => (
                      <option key={t} value={t}>{t}</option>
                    ))}
                  </select>
                </div>

                {/* Column */}
                <div>
                  <label className="block text-xs text-gray-500 mb-1">Column</label>
                  <select
                    value={f.column}
                    onChange={(e) => updateFilter(f.id, { column: e.target.value })}
                    className="w-full text-xs border rounded-md px-2 py-1.5 bg-white font-mono focus:outline-none focus:ring-1 focus:ring-folio-400"
                  >
                    {cols.map((c) => (
                      <option key={c.name} value={c.name}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>

                {/* Remove */}
                <div className="pt-5">
                  <button
                    onClick={() => removeFilter(f.id)}
                    className="text-gray-400 hover:text-red-500 p-1"
                    title="Remove filter"
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              </div>

              {/* Operator + Value row */}
              <div className="mt-2 grid grid-cols-[auto_1fr] gap-2 items-center">
                <select
                  value={f.op}
                  onChange={(e) => updateFilter(f.id, { op: e.target.value })}
                  className="text-xs border rounded-md px-2 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-folio-400"
                >
                  {OPERATORS.map((op) => (
                    <option key={op.value} value={op.value}>{op.label}</option>
                  ))}
                </select>

                {!noValue && !isBetween && (
                  <input
                    type="text"
                    value={f.value}
                    onChange={(e) => updateFilter(f.id, { value: e.target.value })}
                    placeholder={f.op === 'IN' ? 'val1, val2, val3' : f.op === 'LIKE' || f.op === 'NOT LIKE' || f.op === 'ILIKE' ? '%pattern%' : 'value'}
                    className="w-full text-xs border rounded-md px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-folio-400"
                  />
                )}

                {isBetween && (
                  <div className="flex items-center gap-2">
                    <input
                      type="text"
                      value={f.value}
                      onChange={(e) => updateFilter(f.id, { value: e.target.value })}
                      placeholder="from"
                      className="flex-1 text-xs border rounded-md px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-folio-400"
                    />
                    <span className="text-xs text-gray-400">and</span>
                    <input
                      type="text"
                      value={f.value2 || ''}
                      onChange={(e) => updateFilter(f.id, { value2: e.target.value })}
                      placeholder="to"
                      className="flex-1 text-xs border rounded-md px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-folio-400"
                    />
                  </div>
                )}

                {noValue && (
                  <span className="text-xs text-gray-400 italic">no value needed</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
