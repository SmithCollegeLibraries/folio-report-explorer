import { useState, useMemo, useCallback } from 'react';
import { Search, ChevronRight, ChevronDown, Database, Check, Link2, ArrowUpRight, ArrowDownLeft } from 'lucide-react';
import type { TableSummary, TableDetail } from '../types';

interface Props {
  tables: Record<string, TableSummary>;
  selectedTables: string[];
  onToggleTable: (name: string) => void;
  /** Detail data for selected tables (includes FK relationships) */
  tableDetails?: Record<string, TableDetail>;
}

interface SchemaGroup {
  schema: string;
  label: string;
  tables: { name: string; info: TableSummary }[];
}

interface ConnectedTable {
  name: string;
  /** Which selected table(s) this connects through */
  connections: {
    fromTable: string;
    column: string;
    direction: 'parent' | 'child';
  }[];
}

function extractSchema(name: string): string {
  const dotIdx = name.indexOf('.');
  if (dotIdx >= 0) return name.substring(0, dotIdx);
  const parts = name.split('_');
  return parts.length > 1 ? parts[0] : 'other';
}

function formatSchemaLabel(schema: string): string {
  return schema.replace(/^folio_/, '').replace(/_/g, ' ');
}

function shortName(fullName: string): string {
  const dotIdx = fullName.indexOf('.');
  return dotIdx >= 0 ? fullName.substring(dotIdx + 1) : fullName;
}

function buildGroups(tables: Record<string, TableSummary>): SchemaGroup[] {
  const map: Record<string, { name: string; info: TableSummary }[]> = {};
  for (const [name, info] of Object.entries(tables)) {
    // Skip subtables in builder picker — they can be added but are less common
    if (info.type === 'SUBTABLE') continue;
    const schema = extractSchema(name);
    if (!map[schema]) map[schema] = [];
    map[schema].push({ name, info });
  }
  return Object.entries(map)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([schema, tables]) => ({
      schema,
      label: formatSchemaLabel(schema),
      tables: tables.sort((a, b) => a.name.localeCompare(b.name)),
    }));
}

export default function TablePicker({ tables, selectedTables, onToggleTable, tableDetails }: Props) {
  const [search, setSearch] = useState('');
  const [expandedSchemas, setExpandedSchemas] = useState<Set<string>>(new Set());
  const [connectedExpanded, setConnectedExpanded] = useState(true);
  const selectedSet = useMemo(() => new Set(selectedTables), [selectedTables]);

  // Compute connected tables from FK relationships of selected tables
  const connectedTables = useMemo(() => {
    if (!tableDetails || selectedTables.length === 0) return [];
    const map = new Map<string, ConnectedTable['connections']>();
    for (const selTable of selectedTables) {
      const detail = tableDetails[selTable];
      if (!detail?.relationships) continue;
      // Parent tables (tables this one references via FK)
      for (const rel of detail.relationships.parents || []) {
        const parentName = rel.parent_table;
        if (!parentName || !tables[parentName]) continue;
        if (!map.has(parentName)) map.set(parentName, []);
        map.get(parentName)!.push({
          fromTable: selTable,
          column: rel.local_column,
          direction: 'parent',
        });
      }
      // Child tables (tables that reference this one)
      for (const rel of detail.relationships.children || []) {
        const childName = rel.child_table;
        if (!childName || !tables[childName]) continue;
        if (!map.has(childName)) map.set(childName, []);
        map.get(childName)!.push({
          fromTable: selTable,
          column: rel.local_column,
          direction: 'child',
        });
      }
    }
    // Build the list, excluding already-selected tables
    const result: ConnectedTable[] = [];
    for (const [name, connections] of map) {
      if (selectedSet.has(name)) continue;
      result.push({ name, connections });
    }
    return result.sort((a, b) => a.name.localeCompare(b.name));
  }, [tableDetails, selectedTables, selectedSet, tables]);

  // Filter connected tables by search
  const filteredConnected = useMemo(() => {
    if (!search) return connectedTables;
    const lower = search.toLowerCase();
    return connectedTables.filter(
      (ct) => ct.name.toLowerCase().includes(lower) || shortName(ct.name).toLowerCase().includes(lower),
    );
  }, [connectedTables, search]);

  const toggleSchema = useCallback((schema: string) => {
    setExpandedSchemas(prev => {
      const next = new Set(prev);
      next.has(schema) ? next.delete(schema) : next.add(schema);
      return next;
    });
  }, []);

  const filtered = useMemo(() => {
    if (!search) return tables;
    const lower = search.toLowerCase();
    const result: Record<string, TableSummary> = {};
    for (const [name, info] of Object.entries(tables)) {
      if (name.toLowerCase().includes(lower) || shortName(name).toLowerCase().includes(lower)) {
        result[name] = info;
      }
    }
    return result;
  }, [tables, search]);

  const groups = useMemo(() => buildGroups(filtered), [filtered]);
  const isSearching = search.length > 0;

  return (
    <div className="flex flex-col h-full">
      {/* Search */}
      <div className="p-3 border-b">
        <div className="relative">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Search tables…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-9 pr-3 py-2 text-sm border rounded-md focus:outline-none focus:ring-2 focus:ring-folio-500"
          />
        </div>
        <div className="mt-1.5 text-xs text-gray-500">
          {selectedTables.length} selected
        </div>
      </div>

      {/* Table list */}
      <div className="flex-1 overflow-y-auto">
        {/* Connected Tables section */}
        {filteredConnected.length > 0 && (
          <div>
            <button
              onClick={() => setConnectedExpanded((e) => !e)}
              className="w-full flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 sticky top-0 z-20 border-b border-emerald-100"
            >
              {connectedExpanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
              <Link2 size={12} className="text-emerald-500" />
              <span>CONNECTED TABLES</span>
              <span className="ml-auto bg-emerald-200 text-emerald-800 text-xs px-1.5 rounded-full font-normal">
                {filteredConnected.length}
              </span>
            </button>
            {connectedExpanded && filteredConnected.map((ct) => (
              <button
                key={ct.name}
                onClick={() => onToggleTable(ct.name)}
                className="w-full flex items-center gap-2 px-4 py-1.5 text-left hover:bg-emerald-50/50 transition-colors border-l-2 border-emerald-300"
              >
                <div className="w-4 h-4 rounded border border-emerald-400 flex items-center justify-center flex-shrink-0 bg-white">
                  <span className="text-emerald-500 text-xs">+</span>
                </div>
                <div className="min-w-0 flex-1">
                  <div className="font-mono text-xs truncate" title={ct.name}>
                    {shortName(ct.name)}
                  </div>
                  <div className="text-xs text-gray-400 space-x-1">
                    {ct.connections.slice(0, 2).map((conn, i) => (
                      <span key={i} className="inline-flex items-center gap-0.5">
                        {conn.direction === 'parent'
                          ? <ArrowUpRight size={10} className="text-blue-400" />
                          : <ArrowDownLeft size={10} className="text-amber-400" />
                        }
                        <span className="font-mono">{conn.column}</span>
                        <span className="text-gray-300">→</span>
                        <span>{shortName(conn.fromTable)}</span>
                      </span>
                    ))}
                    {ct.connections.length > 2 && (
                      <span className="text-gray-300">+{ct.connections.length - 2} more</span>
                    )}
                  </div>
                </div>
              </button>
            ))}
          </div>
        )}

        {groups.map((group) => {
          const isExpanded = isSearching || expandedSchemas.has(group.schema);
          const selectedInGroup = group.tables.filter(t => selectedSet.has(t.name)).length;
          return (
            <div key={group.schema}>
              <button
                onClick={() => toggleSchema(group.schema)}
                className="w-full flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-600 bg-gray-50 hover:bg-gray-100 sticky top-0 z-10 border-b border-gray-100"
              >
                {isExpanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                <Database size={12} className="text-gray-400" />
                <span className="uppercase truncate">{group.label}</span>
                <span className="ml-auto text-gray-400 font-normal">
                  {selectedInGroup > 0 && (
                    <span className="text-folio-600 mr-1">{selectedInGroup} sel</span>
                  )}
                  {group.tables.length}
                </span>
              </button>

              {isExpanded && group.tables.map((t) => {
                const isSelected = selectedSet.has(t.name);
                return (
                  <button
                    key={t.name}
                    onClick={() => onToggleTable(t.name)}
                    className={`w-full flex items-center gap-2 px-4 py-1.5 text-left hover:bg-gray-50 transition-colors ${
                      isSelected ? 'bg-folio-50' : ''
                    }`}
                  >
                    <div className={`w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 ${
                      isSelected
                        ? 'bg-folio-600 border-folio-600'
                        : 'border-gray-300'
                    }`}>
                      {isSelected && <Check size={12} className="text-white" />}
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="font-mono text-xs truncate" title={t.name}>
                        {shortName(t.name)}
                      </div>
                      <div className="text-xs text-gray-400">
                        {t.info.column_count} cols
                        {t.info.parent_count > 0 && <span className="ml-2">{t.info.parent_count} FK↑</span>}
                      </div>
                    </div>
                  </button>
                );
              })}
            </div>
          );
        })}
      </div>
    </div>
  );
}
