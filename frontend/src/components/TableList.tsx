import { useState, useMemo, useCallback } from 'react';
import { Search, ChevronRight, ChevronDown, Database, Table2, Layers } from 'lucide-react';
import type { TableSummary } from '../types';

interface Props {
  tables: Record<string, TableSummary>;
  selectedTable: string | null;
  onSelectTable: (name: string) => void;
}

interface BaseTableEntry {
  name: string;
  info: TableSummary;
  subtables: { name: string; info: TableSummary; leafName: string }[];
}

interface SchemaGroup {
  schema: string;
  label: string;
  baseTables: BaseTableEntry[];
  totalCount: number;
}

/** Extract a short display name for a table */
function shortName(fullName: string): string {
  const dotIdx = fullName.indexOf('.');
  return dotIdx >= 0 ? fullName.substring(dotIdx + 1) : fullName;
}

/** Extract schema prefix from a table name, preferring the backend-supplied domain */
function extractSchema(name: string, info?: TableSummary): string {
  if (info?.domain) return info.domain;
  const dotIdx = name.indexOf('.');
  if (dotIdx >= 0) return name.substring(0, dotIdx);
  // For non-schema-qualified names, group by first word
  const parts = name.split('_');
  return parts.length > 1 ? parts[0] : 'other';
}

/** Pretty-print a schema name */
function formatSchemaLabel(schema: string): string {
  return schema.replace(/^folio_/, '').replace(/_/g, ' ');
}

/** Build hierarchical groups: schema → base tables → subtables */
function buildHierarchy(tables: Record<string, TableSummary>): SchemaGroup[] {
  const schemaMap: Record<string, { baseTables: Record<string, BaseTableEntry> }> = {};

  // First pass: collect base tables
  for (const [name, info] of Object.entries(tables)) {
    if (info.type === 'SUBTABLE') continue;
    const schema = extractSchema(name, info);
    if (!schemaMap[schema]) schemaMap[schema] = { baseTables: {} };
    schemaMap[schema].baseTables[name] = { name, info, subtables: [] };
  }

  // Second pass: attach subtables to their parents
  for (const [name, info] of Object.entries(tables)) {
    if (info.type !== 'SUBTABLE') continue;
    const parentName = info.parent_table;
    const schema = extractSchema(name, info);

    if (!schemaMap[schema]) schemaMap[schema] = { baseTables: {} };

    // Compute leaf name: portion after the parent table name
    let leafName = shortName(name);
    if (parentName) {
      const parentShort = shortName(parentName);
      if (leafName.startsWith(parentShort)) {
        leafName = leafName.substring(parentShort.length).replace(/^__/, '');
      }
    }

    // Find parent in this schema or any schema
    let attached = false;
    if (parentName) {
      const parentSchema = extractSchema(parentName, tables[parentName]);
      if (schemaMap[parentSchema]?.baseTables[parentName]) {
        schemaMap[parentSchema].baseTables[parentName].subtables.push({ name, info, leafName });
        attached = true;
      }
    }

    // If parent not found, add as standalone entry
    if (!attached) {
      schemaMap[schema].baseTables[name] = { name, info, subtables: [] };
    }
  }

  // Build sorted output
  return Object.entries(schemaMap)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([schema, group]) => {
      const baseTables = Object.values(group.baseTables).sort((a, b) =>
        a.name.localeCompare(b.name)
      );
      // Sort subtables within each parent
      baseTables.forEach(bt => bt.subtables.sort((a, b) => a.leafName.localeCompare(b.leafName)));
      const totalCount = baseTables.reduce((sum, bt) => sum + 1 + bt.subtables.length, 0);
      return { schema, label: formatSchemaLabel(schema), baseTables, totalCount };
    });
}

export default function TableList({ tables, selectedTable, onSelectTable }: Props) {
  const [search, setSearch] = useState('');
  const [showSubtables, setShowSubtables] = useState(true);
  const [expandedSchemas, setExpandedSchemas] = useState<Set<string>>(new Set());
  const [expandedParents, setExpandedParents] = useState<Set<string>>(new Set());

  const toggleSchema = useCallback((schema: string) => {
    setExpandedSchemas(prev => {
      const next = new Set(prev);
      next.has(schema) ? next.delete(schema) : next.add(schema);
      return next;
    });
  }, []);

  const toggleParent = useCallback((name: string) => {
    setExpandedParents(prev => {
      const next = new Set(prev);
      next.has(name) ? next.delete(name) : next.add(name);
      return next;
    });
  }, []);

  // Filter tables by search term
  const filtered = useMemo(() => {
    const source = showSubtables
      ? tables
      : Object.fromEntries(Object.entries(tables).filter(([, info]) => info.type !== 'SUBTABLE'));

    if (!search) {
      return source;
    }
    const lower = search.toLowerCase();
    const result: Record<string, TableSummary> = {};
    for (const [name, info] of Object.entries(source)) {
      const sName = shortName(name).toLowerCase();
      if (name.toLowerCase().includes(lower) || sName.includes(lower)) {
        result[name] = info;
      }
    }
    return result;
  }, [tables, search, showSubtables]);

  const groups = useMemo(() => buildHierarchy(filtered), [filtered]);

  // Count subtables in the full (unfiltered) set
  const subtableCount = useMemo(
    () => Object.values(tables).filter(t => t.type === 'SUBTABLE').length,
    [tables]
  );

  // Auto-expand schemas that match search
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
        <div className="flex items-center justify-between mt-1.5">
          <span className="text-xs text-gray-500">
            {Object.keys(filtered).length} tables
          </span>
          {subtableCount > 0 && !isSearching && (
            <label className="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer">
              <input
                type="checkbox"
                checked={showSubtables}
                onChange={(e) => setShowSubtables(e.target.checked)}
                className="rounded border-gray-300 text-folio-500 focus:ring-folio-500 h-3.5 w-3.5"
              />
              Subtables ({subtableCount})
            </label>
          )}
        </div>
      </div>

      {/* Hierarchical table list */}
      <div className="flex-1 overflow-y-auto">
        {groups.map((group) => {
          const isExpanded = isSearching || expandedSchemas.has(group.schema);
          return (
            <div key={group.schema}>
              {/* Schema header */}
              <button
                onClick={() => toggleSchema(group.schema)}
                className="w-full flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-600 bg-gray-50 hover:bg-gray-100 sticky top-0 z-10 border-b border-gray-100"
              >
                {isExpanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                <Database size={12} className="text-gray-400" />
                <span className="uppercase truncate">{group.label}</span>
                <span className="ml-auto text-gray-400 font-normal">{group.totalCount}</span>
              </button>

              {isExpanded && group.baseTables.map((bt) => {
                const hasSubtables = bt.subtables.length > 0;
                const isParentExpanded = isSearching || expandedParents.has(bt.name);
                const isSelected = selectedTable === bt.name;
                const displayName = shortName(bt.name);

                return (
                  <div key={bt.name}>
                    {/* Base table row */}
                    <div className={`flex items-center border-l-3 transition-colors ${
                      isSelected
                        ? 'bg-folio-50 border-l-folio-500'
                        : 'border-l-transparent hover:bg-gray-50'
                    }`}>
                      {hasSubtables ? (
                        <button
                          onClick={() => toggleParent(bt.name)}
                          className="pl-5 pr-1 py-2 text-gray-400 hover:text-gray-600 flex-shrink-0"
                          title={`${bt.subtables.length} subtables`}
                        >
                          {isParentExpanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                        </button>
                      ) : (
                        <span className="w-[34px] flex-shrink-0" />
                      )}
                      <button
                        onClick={() => onSelectTable(bt.name)}
                        className="flex-1 text-left py-2 pr-3 min-w-0"
                      >
                        <div className="flex items-center gap-1.5">
                          <Table2 size={12} className="text-gray-400 flex-shrink-0" />
                          <span className={`font-mono text-xs truncate ${
                            isSelected ? 'font-medium text-folio-700' : 'text-gray-700'
                          }`} title={bt.name}>
                            {displayName}
                          </span>
                        </div>
                        {displayName !== bt.name && (
                          <div className="pl-[18px] text-[10px] text-gray-400 truncate" title={bt.name}>
                            {bt.name}
                          </div>
                        )}
                        <div className="flex gap-2 mt-0.5 text-xs text-gray-400 pl-[18px]">
                          <span>{bt.info.column_count} cols</span>
                          {bt.info.parent_count > 0 && <span>{bt.info.parent_count} FK↑</span>}
                          {bt.info.child_count > 0 && <span>{bt.info.child_count} FK↓</span>}
                          {hasSubtables && (
                            <span className="text-folio-500">{bt.subtables.length} sub</span>
                          )}
                        </div>
                      </button>
                    </div>

                    {/* Subtable rows */}
                    {hasSubtables && isParentExpanded && (
                      <div className="bg-gray-50/50">
                        {bt.subtables.map((st) => {
                          const isSubSelected = selectedTable === st.name;
                          return (
                            <button
                              key={st.name}
                              onClick={() => onSelectTable(st.name)}
                              className={`w-full text-left pl-12 pr-3 py-1.5 text-xs hover:bg-folio-50 border-l-3 transition-colors ${
                                isSubSelected
                                  ? 'bg-folio-50 border-l-folio-500 font-medium'
                                  : 'border-l-transparent'
                              }`}
                              title={st.name}
                            >
                              <div className="flex items-center gap-1.5">
                                <Layers size={10} className="text-gray-400 flex-shrink-0" />
                                <span className="font-mono truncate text-gray-600">{st.leafName}</span>
                              </div>
                              {st.leafName !== st.name && (
                                <div className="text-[10px] text-gray-400 truncate pl-[16px]" title={st.name}>
                                  {st.name}
                                </div>
                              )}
                              <div className="text-xs text-gray-400 pl-[16px]">
                                {st.info.column_count} cols
                              </div>
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          );
        })}
      </div>
    </div>
  );
}
