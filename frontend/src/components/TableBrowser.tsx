import { useMemo, useState } from 'react';
import type { TableDetail, TableSummary } from '../types';
import {
  Search, ChevronRight, ChevronDown, Check, Plus, Link2, Database,
} from 'lucide-react';

interface ConnectedInfo {
  name: string;
  links: { fromTable: string; column: string; toColumn: string; direction: 'parent' | 'child' }[];
}

interface Props {
  tables: Record<string, TableSummary>;
  selectedTables: string[];
  tableDetails: Record<string, TableDetail>;
  onAddTable: (name: string) => void;
  onRemoveTable: (name: string) => void;
}

function shortName(fullName: string): string {
  const dotIdx = fullName.indexOf('.');
  return dotIdx >= 0 ? fullName.substring(dotIdx + 1) : fullName;
}

/** Extract domain group from table name, preferring the backend-supplied domain */
function domainOf(name: string, info?: TableSummary): string {
  if (info?.domain) return info.domain;
  // Prefixed: "inventory.holdings_record" → "inventory"
  const dot = name.indexOf('.');
  if (dot >= 0) return name.substring(0, dot);
  // Unprefixed: "circulation_loans" → "circulation"
  const under = name.indexOf('_');
  return under >= 0 ? name.substring(0, under) : name;
}

/** Human-readable label for a domain */
function domainLabel(domain: string): string {
  return domain.charAt(0).toUpperCase() + domain.slice(1);
}

export default function TableBrowser({
  tables,
  selectedTables,
  tableDetails,
  onAddTable,
  onRemoveTable,
}: Props) {
  const [search, setSearch] = useState('');
  const [showSubtables, setShowSubtables] = useState(true);
  const [expandedDomains, setExpandedDomains] = useState<Set<string>>(new Set());

  const selectedSet = useMemo(() => new Set(selectedTables), [selectedTables]);

  // Compute connected tables
  const connectedTables = useMemo<ConnectedInfo[]>(() => {
    if (selectedTables.length === 0) return [];
    const map = new Map<string, ConnectedInfo['links']>();

    for (const selTable of selectedTables) {
      const detail = tableDetails[selTable];
      if (!detail?.relationships) continue;

      for (const rel of detail.relationships.parents || []) {
        const parentName = rel.parent_table;
        if (!parentName || !tables[parentName] || selectedSet.has(parentName)) continue;
        if (!showSubtables && tables[parentName]?.type === 'SUBTABLE') continue;
        if (!map.has(parentName)) map.set(parentName, []);
        map.get(parentName)!.push({
          fromTable: selTable,
          column: rel.local_column,
          toColumn: rel.parent_column || 'id',
          direction: 'parent',
        });
      }
      for (const rel of detail.relationships.children || []) {
        const childName = rel.child_table;
        if (!childName || !tables[childName] || selectedSet.has(childName)) continue;
        if (!showSubtables && tables[childName]?.type === 'SUBTABLE') continue;
        if (!map.has(childName)) map.set(childName, []);
        map.get(childName)!.push({
          fromTable: selTable,
          column: rel.local_column,
          toColumn: rel.child_column || rel.local_column,
          direction: 'child',
        });
      }
    }

    const result: ConnectedInfo[] = [];
    for (const [name, links] of map) {
      result.push({ name, links });
    }
    return result.sort((a, b) => b.links.length - a.links.length);
  }, [tableDetails, selectedTables, selectedSet, tables, showSubtables]);

  const connectedSet = useMemo(
    () => new Set(connectedTables.map((ct) => ct.name)),
    [connectedTables],
  );

  const subtableCount = useMemo(
    () => Object.values(tables).filter((t) => t.type === 'SUBTABLE').length,
    [tables],
  );

  // Group tables by domain, with optional subtable visibility
  const { groups, domainOrder } = useMemo(() => {
    const g = new Map<string, { name: string; info: TableSummary }[]>();

    for (const [name, info] of Object.entries(tables)) {
      if (!showSubtables && info.type === 'SUBTABLE') continue;
      const domain = domainOf(name, info);
      if (!g.has(domain)) g.set(domain, []);
      g.get(domain)!.push({ name, info });
    }

    // Sort tables within each group
    for (const list of g.values()) {
      list.sort((a, b) => a.name.localeCompare(b.name));
    }

    // Sort domains: put domains with selected tables first, then by size
    const order = [...g.keys()].sort((a, b) => {
      const aHasSelected = g.get(a)!.some((t) => selectedSet.has(t.name));
      const bHasSelected = g.get(b)!.some((t) => selectedSet.has(t.name));
      if (aHasSelected !== bHasSelected) return aHasSelected ? -1 : 1;
      return (g.get(b)!.length || 0) - (g.get(a)!.length || 0);
    });

    return { groups: g, domainOrder: order };
  }, [tables, selectedSet, showSubtables]);

  // Filter by search
  const filteredDomains = useMemo(() => {
    if (!search) return domainOrder;
    const lower = search.toLowerCase();
    return domainOrder.filter((domain) => {
      if (domain.toLowerCase().includes(lower)) return true;
      const list = groups.get(domain) || [];
      return list.some(
        (t) =>
          t.name.toLowerCase().includes(lower) ||
          shortName(t.name).toLowerCase().includes(lower),
      );
    });
  }, [domainOrder, groups, search]);

  const toggleDomain = (domain: string) => {
    setExpandedDomains((prev) => {
      const next = new Set(prev);
      if (next.has(domain)) next.delete(domain);
      else next.add(domain);
      return next;
    });
  };

  const filteredTablesInDomain = (domain: string) => {
    const list = groups.get(domain) || [];
    if (!search) return list;
    const lower = search.toLowerCase();
    // If the domain name matches, show all tables
    if (domain.toLowerCase().includes(lower)) return list;
    return list.filter(
      (t) =>
        t.name.toLowerCase().includes(lower) ||
        shortName(t.name).toLowerCase().includes(lower),
    );
  };

  return (
    <div className="flex flex-col h-full bg-white">
      {/* Search box */}
      <div className="p-3 border-b flex-shrink-0">
        <div className="relative">
          <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Search tables…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full text-sm border rounded-lg pl-8 pr-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-folio-500"
          />
        </div>
        {subtableCount > 0 && (
          <label className="mt-2 inline-flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer select-none">
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

      {/* Scrollable list */}
      <div className="flex-1 overflow-y-auto">
        {/* Connected Tables section */}
        {connectedTables.length > 0 && !search && (
          <div className="border-b">
            <div className="px-3 py-1.5 text-[10px] font-bold text-emerald-700 bg-emerald-50 uppercase tracking-wider flex items-center gap-1">
              <Link2 size={10} />
              Connected Tables ({connectedTables.length})
            </div>
            {connectedTables.slice(0, 12).map((ct) => (
              <button
                key={ct.name}
                onClick={() => onAddTable(ct.name)}
                className="w-full text-left px-3 py-1.5 text-xs hover:bg-emerald-50 flex items-center gap-2 border-l-2 border-emerald-300"
              >
                <Plus size={12} className="text-emerald-500 shrink-0" />
                <div className="min-w-0 flex-1">
                  <div className="font-mono truncate">{shortName(ct.name)}</div>
                  {shortName(ct.name) !== ct.name && (
                    <div className="text-[10px] text-gray-400 truncate">{ct.name}</div>
                  )}
                </div>
                <span className="text-[10px] text-gray-400 shrink-0">
                  {ct.links.length} link{ct.links.length > 1 ? 's' : ''}
                </span>
              </button>
            ))}
            {connectedTables.length > 12 && (
              <div className="px-3 py-1 text-[10px] text-gray-400 text-center">
                +{connectedTables.length - 12} more
              </div>
            )}
          </div>
        )}

        {/* Domain groups */}
        {filteredDomains.map((domain) => {
          const domainTables = filteredTablesInDomain(domain);
          if (domainTables.length === 0) return null;
          const isExpanded = expandedDomains.has(domain) || !!search;
          const hasSelected = domainTables.some((t) => selectedSet.has(t.name));
          const selectedCount = domainTables.filter((t) => selectedSet.has(t.name)).length;
          const connectedCount = domainTables.filter((t) => connectedSet.has(t.name)).length;

          return (
            <div key={domain} className="border-b last:border-b-0">
              {/* Domain header */}
              <button
                onClick={() => toggleDomain(domain)}
                className={`w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50 ${
                  hasSelected ? 'bg-folio-50/50' : ''
                }`}
              >
                {isExpanded ? (
                  <ChevronDown size={14} className="text-gray-400 shrink-0" />
                ) : (
                  <ChevronRight size={14} className="text-gray-400 shrink-0" />
                )}
                <Database size={12} className="text-gray-400 shrink-0" />
                <span className="font-medium text-gray-700 flex-1 text-left">
                  {domainLabel(domain)}
                </span>
                <span className="text-[10px] text-gray-400">{domainTables.length}</span>
                {selectedCount > 0 && (
                  <span className="text-[10px] bg-folio-100 text-folio-700 px-1.5 rounded-full">
                    {selectedCount} selected
                  </span>
                )}
                {connectedCount > 0 && !search && (
                  <span className="text-[10px] bg-emerald-100 text-emerald-700 px-1.5 rounded-full">
                    {connectedCount} linked
                  </span>
                )}
              </button>

              {/* Tables in domain */}
              {isExpanded && (
                <div className="pb-1">
                  {domainTables.map(({ name, info }) => {
                    const isSel = selectedSet.has(name);
                    const isConn = connectedSet.has(name);

                    return (
                      <button
                        key={name}
                        onClick={() => (isSel ? onRemoveTable(name) : onAddTable(name))}
                        className={`w-full text-left pl-8 pr-3 py-1.5 text-xs flex items-center gap-2 transition-colors ${
                          isSel
                            ? 'bg-folio-50 text-folio-800 font-medium'
                            : isConn
                              ? 'hover:bg-emerald-50 border-l-2 border-emerald-200 ml-0 pl-[30px]'
                              : 'hover:bg-gray-50 text-gray-700'
                        }`}
                      >
                        {isSel ? (
                          <Check size={12} className="text-folio-600 shrink-0" />
                        ) : isConn ? (
                          <Link2 size={12} className="text-emerald-500 shrink-0" />
                        ) : (
                          <Plus size={12} className="text-gray-300 shrink-0" />
                        )}
                        <div className="min-w-0 flex-1">
                          <div className="font-mono truncate">{shortName(name)}</div>
                          {shortName(name) !== name && (
                            <div className="text-[10px] text-gray-400 truncate">{name}</div>
                          )}
                        </div>
                        <span className="text-[10px] text-gray-400 shrink-0">
                          {info.column_count} col{info.type === 'SUBTABLE' ? ' · sub' : ''}
                        </span>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}

        {filteredDomains.length === 0 && search && (
          <div className="px-4 py-8 text-center text-gray-400 text-sm">
            No tables matching "{search}"
          </div>
        )}
      </div>

      {/* Footer summary */}
      <div className="px-3 py-2 border-t bg-gray-50 text-[10px] text-gray-500 flex-shrink-0">
        {selectedTables.length} table{selectedTables.length !== 1 ? 's' : ''} selected
        {connectedTables.length > 0 && ` · ${connectedTables.length} connected`}
        {' · '}
        {Object.keys(tables).length} total
      </div>
    </div>
  );
}
