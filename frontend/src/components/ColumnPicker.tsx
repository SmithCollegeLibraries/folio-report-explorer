import { useState } from 'react';
import { ChevronDown, ChevronRight, Check } from 'lucide-react';
import type { ColumnDef, SelectedColumn, AggregateFunction } from '../types';

interface Props {
  tableColumns: Record<string, ColumnDef[]>;
  selectedColumns: SelectedColumn[];
  onColumnsChange: (columns: SelectedColumn[]) => void;
}

const AGGREGATES: { value: AggregateFunction | ''; label: string }[] = [
  { value: '', label: 'None' },
  { value: 'COUNT', label: 'COUNT' },
  { value: 'SUM', label: 'SUM' },
  { value: 'AVG', label: 'AVG' },
  { value: 'MIN', label: 'MIN' },
  { value: 'MAX', label: 'MAX' },
];

export default function ColumnPicker({
  tableColumns,
  selectedColumns,
  onColumnsChange,
}: Props) {
  const tables = Object.keys(tableColumns);
  const [expandedTables, setExpandedTables] = useState<Set<string>>(
    () => new Set(tables),
  );

  const selectedSet = new Set(
    selectedColumns.map((c) => `${c.table}.${c.column}`),
  );

  const toggleTable = (t: string) => {
    setExpandedTables((prev) => {
      const next = new Set(prev);
      next.has(t) ? next.delete(t) : next.add(t);
      return next;
    });
  };

  const isSelected = (table: string, column: string) =>
    selectedSet.has(`${table}.${column}`);

  const toggleColumn = (table: string, col: ColumnDef) => {
    const key = `${table}.${col.name}`;
    if (selectedSet.has(key)) {
      onColumnsChange(
        selectedColumns.filter(
          (c) => `${c.table}.${c.column}` !== key,
        ),
      );
    } else {
      onColumnsChange([
        ...selectedColumns,
        { table, column: col.name, aggregate: '' },
      ]);
    }
  };

  const toggleAll = (table: string) => {
    const cols = tableColumns[table] || [];
    const allIn = cols.every((c) =>
      selectedSet.has(`${table}.${c.name}`),
    );
    if (allIn) {
      // Remove all for this table
      const tableKeys = new Set(
        cols.map((c) => `${table}.${c.name}`),
      );
      onColumnsChange(
        selectedColumns.filter(
          (c) => !tableKeys.has(`${c.table}.${c.column}`),
        ),
      );
    } else {
      // Add missing
      const toAdd = cols
        .filter((c) => !selectedSet.has(`${table}.${c.name}`))
        .map((c) => ({
          table,
          column: c.name,
          aggregate: '' as const,
        }));
      onColumnsChange([...selectedColumns, ...toAdd]);
    }
  };

  const updateAggregate = (
    table: string,
    column: string,
    aggregate: AggregateFunction | '',
  ) => {
    onColumnsChange(
      selectedColumns.map((c) =>
        c.table === table && c.column === column
          ? { ...c, aggregate }
          : c,
      ),
    );
  };

  const getAggregate = (table: string, column: string) => {
    return (
      selectedColumns.find(
        (c) => c.table === table && c.column === column,
      )?.aggregate ?? ''
    );
  };

  if (tables.length === 0) {
    return (
      <div className="p-6 text-center text-gray-500 text-sm">
        Select at least one table to pick columns.
      </div>
    );
  }

  return (
    <div className="p-4 space-y-3">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">
          Columns{' '}
          <span className="font-normal text-gray-400">
            ({selectedColumns.length} selected)
          </span>
        </h3>
      </div>

      {tables.map((table) => {
        const cols = tableColumns[table] || [];
        const isExpanded = expandedTables.has(table);
        const selectedInTable = cols.filter((c) =>
          selectedSet.has(`${table}.${c.name}`),
        ).length;
        const allSelected = selectedInTable === cols.length && cols.length > 0;

        return (
          <div
            key={table}
            className="border rounded-lg bg-white shadow-sm overflow-hidden"
          >
            {/* Table header */}
            <div className="flex items-center gap-2 px-3 py-2 bg-folio-50 border-b">
              <button
                onClick={() => toggleTable(table)}
                className="flex items-center gap-1 flex-1 min-w-0"
              >
                {isExpanded ? (
                  <ChevronDown size={14} className="text-gray-400" />
                ) : (
                  <ChevronRight size={14} className="text-gray-400" />
                )}
                <span className="font-mono text-sm font-semibold text-folio-800 truncate">
                  {table}
                </span>
                <span className="text-xs text-gray-400 ml-1">
                  {selectedInTable}/{cols.length}
                </span>
              </button>
              <button
                onClick={() => toggleAll(table)}
                className="text-xs text-folio-600 hover:text-folio-800 whitespace-nowrap"
              >
                {allSelected ? 'Deselect All' : 'Select All'}
              </button>
            </div>

            {/* Columns */}
            {isExpanded && (
              <div>
                {cols.map((col) => {
                  const colName = col.name;
                  const checked = isSelected(table, colName);
                  return (
                    <div
                      key={colName}
                      className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 border-b border-gray-50 last:border-0"
                    >
                      {/* Checkbox */}
                      <button
                        onClick={() => toggleColumn(table, col)}
                        className={`w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 ${
                          checked
                            ? 'bg-folio-600 border-folio-600'
                            : 'border-gray-300'
                        }`}
                      >
                        {checked && (
                          <Check size={12} className="text-white" />
                        )}
                      </button>

                      {/* Column name */}
                      <span className="font-mono text-xs flex-1 min-w-0 truncate">
                        {colName}
                      </span>

                      {/* Type badge */}
                      <span className="text-xs text-gray-400 whitespace-nowrap">
                        {col.type}
                      </span>

                      {/* Nullable */}
                      {col.nullable && (
                        <span
                          className="text-xs text-yellow-500"
                          title="Nullable"
                        >
                          ?
                        </span>
                      )}

                      {/* FK */}
                      {col.parents && col.parents.length > 0 && (
                        <span
                          className="text-xs text-blue-500"
                          title="Foreign Key"
                        >
                          FK
                        </span>
                      )}

                      {/* Aggregate dropdown — only show if selected */}
                      {checked && (
                        <select
                          value={getAggregate(table, colName)}
                          onChange={(e) =>
                            updateAggregate(
                              table,
                              colName,
                              e.target.value as AggregateFunction | '',
                            )
                          }
                          className="text-xs border rounded px-1.5 py-0.5 bg-white focus:outline-none focus:ring-1 focus:ring-folio-400"
                          title="Aggregate function"
                        >
                          {AGGREGATES.map((a) => (
                            <option key={a.value} value={a.value}>
                              {a.label}
                            </option>
                          ))}
                        </select>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
