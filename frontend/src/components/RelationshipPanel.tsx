import { useMemo } from 'react';
import type { TableDetail, TableSummary } from '../types';
import { ArrowRight, Table2, Key, Link2, ChevronRight, Maximize2 } from 'lucide-react';

interface Props {
  selectedTables: string[];
  tableDetails: Record<string, TableDetail>;
  tables: Record<string, TableSummary>;
  onShowGraph?: () => void;
}

function shortName(fullName: string): string {
  const dotIdx = fullName.indexOf('.');
  return dotIdx >= 0 ? fullName.substring(dotIdx + 1) : fullName;
}

interface FKRelationship {
  from: string;
  fromColumn: string;
  to: string;
  toColumn: string;
}

export default function RelationshipPanel({
  selectedTables,
  tableDetails,
  tables,
  onShowGraph,
}: Props) {
  // Compute FK relationships between selected tables only
  const relationships = useMemo(() => {
    const selectedSet = new Set(selectedTables);
    const rels: FKRelationship[] = [];
    const seen = new Set<string>();

    for (const t of selectedTables) {
      const detail = tableDetails[t];
      if (!detail?.relationships) continue;

      for (const rel of detail.relationships.parents || []) {
        if (!rel.parent_table || !selectedSet.has(rel.parent_table)) continue;
        const key = `${t}.${rel.local_column}->${rel.parent_table}.${rel.parent_column || 'id'}`;
        if (seen.has(key)) continue;
        seen.add(key);
        rels.push({
          from: t,
          fromColumn: rel.local_column,
          to: rel.parent_table,
          toColumn: rel.parent_column || 'id',
        });
      }
    }
    return rels;
  }, [selectedTables, tableDetails]);

  if (selectedTables.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center h-full text-gray-400 px-6 text-center">
        <Table2 size={32} className="mb-3 text-gray-300" />
        <p className="text-sm font-medium mb-1">No tables selected</p>
        <p className="text-xs">
          Use the Browse tab to select tables, then switch here to see how they connect.
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full overflow-y-auto">
      {/* View Graph button — always visible when tables are selected */}
      {onShowGraph && (
        <div className="p-3 border-b flex-shrink-0">
          <button
            onClick={onShowGraph}
            className="w-full flex items-center justify-center gap-2 bg-folio-600 text-white text-sm font-medium px-3 py-2.5 rounded-lg hover:bg-folio-700 transition-colors shadow-sm"
          >
            <Maximize2 size={14} />
            Open Relationship Graph
          </button>
        </div>
      )}

      {/* Table cards */}
      <div className="p-3 space-y-2">
        {selectedTables.map((t) => {
          const detail = tableDetails[t];
          const info = tables[t];
          const cols = detail?.table?.columns || [];
          const pkCol = info?.primary_key;
          // FK columns for this table
          const fkCols = relationships
            .filter((r) => r.from === t)
            .map((r) => r.fromColumn);
          const fkSet = new Set(fkCols);

          return (
            <div key={t} className="border rounded-lg bg-white overflow-hidden">
              {/* Table header */}
              <div className="px-3 py-2 bg-folio-600 text-white flex items-center gap-2">
                <Table2 size={13} />
                <span className="font-mono text-xs font-semibold truncate flex-1">
                  {shortName(t)}
                </span>
                <span className="text-[10px] text-folio-200">
                  {cols.length} col{cols.length !== 1 ? 's' : ''}
                </span>
              </div>

              {/* Column list (compact) */}
              <div className="px-2 py-1.5">
                {cols.slice(0, 8).map((col: any) => {
                  const isPk = col.name === pkCol || col.name === 'id';
                  const isFk = fkSet.has(col.name);

                  return (
                    <div
                      key={col.name}
                      className={`flex items-center gap-1.5 px-1.5 py-0.5 rounded text-[11px] font-mono ${
                        isFk
                          ? 'bg-amber-50 text-amber-800'
                          : isPk
                            ? 'bg-blue-50 text-blue-800'
                            : 'text-gray-600'
                      }`}
                    >
                      {isPk && <Key size={10} className="text-blue-500 shrink-0" />}
                      {isFk && !isPk && <Link2 size={10} className="text-amber-500 shrink-0" />}
                      {!isPk && !isFk && <span className="w-[10px] shrink-0" />}
                      <span className="truncate flex-1">{col.name}</span>
                      <span className="text-[9px] text-gray-400 shrink-0">
                        {col.type?.toLowerCase().replace('character varying', 'varchar')}
                      </span>
                    </div>
                  );
                })}
                {cols.length > 8 && (
                  <div className="text-[10px] text-gray-400 pl-5 py-0.5">
                    +{cols.length - 8} more columns
                  </div>
                )}
                {cols.length === 0 && (
                  <div className="text-[10px] text-gray-400 py-1 text-center italic">
                    Loading columns…
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* Relationships section */}
      {relationships.length > 0 && (
        <div className="px-3 pb-3">
          <div className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2 flex items-center gap-1">
            <Link2 size={10} />
            Joins ({relationships.length})
          </div>
          <div className="space-y-1.5">
            {relationships.map((rel, i) => (
              <div
                key={i}
                className="flex items-center gap-1 bg-gray-50 border rounded px-2.5 py-2 text-[11px]"
              >
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-1 font-mono">
                    <span className="font-semibold text-folio-700 truncate">
                      {shortName(rel.from)}
                    </span>
                    <span className="text-gray-400">.</span>
                    <span className="text-amber-700 truncate">{rel.fromColumn}</span>
                  </div>
                </div>
                <ArrowRight size={12} className="text-gray-400 shrink-0 mx-1" />
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-1 font-mono">
                    <span className="font-semibold text-folio-700 truncate">
                      {shortName(rel.to)}
                    </span>
                    <span className="text-gray-400">.</span>
                    <span className="text-blue-700 truncate">{rel.toColumn}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* No relationships warning */}
      {selectedTables.length > 1 && relationships.length === 0 && (
        <div className="mx-3 mb-3 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
          <p className="font-medium mb-1">No direct relationships found</p>
          <p className="text-amber-600">
            These tables may not have foreign key connections. Check the Joins tab
            to configure joins manually.
          </p>
        </div>
      )}

      {/* Single table hint */}
      {selectedTables.length === 1 && (
        <div className="mx-3 mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-700 flex items-start gap-2">
          <ChevronRight size={14} className="shrink-0 mt-0.5" />
          <p>
            Add more tables from the Browse tab to see how they connect via foreign keys.
          </p>
        </div>
      )}
    </div>
  );
}
