import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchSchema, fetchTableDetail } from '../api/client';
import TableList from '../components/TableList';
import SchemaAssistant from '../components/SchemaAssistant';
import JoinGraph from '../components/JoinGraph';
import type { TableDetail } from '../types';
import { ExternalLink, Key, ArrowRight } from 'lucide-react';

export default function Explorer() {
  const [selectedTable, setSelectedTable] = useState<string | null>(null);

  const { data: schemaData, isLoading, error } = useQuery({
    queryKey: ['schema'],
    queryFn: () => fetchSchema(),
  });

  const { data: detail } = useQuery({
    queryKey: ['tableDetail', selectedTable],
    queryFn: () => fetchTableDetail(selectedTable!),
    enabled: !!selectedTable,
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <div className="text-gray-500">Loading schema…</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-96">
        <div className="text-red-500">
          Failed to load schema. Is the backend running?
          <pre className="text-xs mt-2">{String(error)}</pre>
        </div>
      </div>
    );
  }

  const tables = schemaData?.tables || {};

  return (
    <div className="flex h-[calc(100vh-56px)]">
      {/* Left: table list — full-width on mobile when no table selected, else hidden */}
      <div className={`
        ${selectedTable ? 'hidden md:flex' : 'flex'}
        md:w-80 w-full border-r bg-white flex-shrink-0 flex-col
      `}>
        <TableList
          tables={tables}
          selectedTable={selectedTable}
          onSelectTable={setSelectedTable}
        />
      </div>

      {/* Right: detail panel — hidden on mobile when no table selected */}
      <div className={`
        ${selectedTable ? 'flex' : 'hidden md:flex'}
        flex-1 overflow-y-auto flex-col
      `}>
        {/* Back button — mobile only */}
        {selectedTable && (
          <button
            onClick={() => setSelectedTable(null)}
            className="md:hidden flex items-center gap-2 px-4 py-2.5 text-sm text-folio-700 border-b bg-white flex-shrink-0"
          >
            ← All Tables
          </button>
        )}
        {!selectedTable ? (
          <div className="flex items-center justify-center h-full text-gray-400">
            <div className="text-center">
              <p className="text-lg mb-2">Select a table to explore</p>
              <p className="text-sm">
                {Object.keys(tables).length} tables available in the FOLIO LDP schema
              </p>
            </div>
          </div>
        ) : detail ? (
          <TableDetailView
            detail={detail}
            onNavigateTable={setSelectedTable}
          />
        ) : (
          <div className="flex items-center justify-center h-full text-gray-400">
            Loading table detail…
          </div>
        )}
      </div>

      {/* Schema AI Assistant */}
      <SchemaAssistant
        selectedTable={selectedTable}
        onNavigateTable={setSelectedTable}
      />
    </div>
  );
}

function TableDetailView({
  detail,
  onNavigateTable,
}: {
  detail: TableDetail;
  onNavigateTable: (name: string) => void;
}) {
  const { name, table, relationships } = detail;
  const [tab, setTab] = useState<'columns' | 'relationships' | 'graph'>('columns');

  return (
    <div className="p-6 max-w-4xl">
      {/* Header */}
      <div className="mb-6">
        <h2 className="text-2xl font-bold font-mono">{name}</h2>
        {table.remarks && (
          <a
            href={table.remarks}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-1 text-sm text-folio-600 hover:underline mt-1"
          >
            API Documentation <ExternalLink size={12} />
          </a>
        )}
        <div className="flex gap-4 mt-2 text-sm text-gray-500">
          <span>{table.columns.length} columns</span>
          <span>{relationships.parents.length} parent FKs</span>
          <span>{relationships.children.length} child FKs</span>
          {table.primary_key && (
            <span className="flex items-center gap-1">
              <Key size={12} /> {table.primary_key}
            </span>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 mb-4 border-b">
        {(['columns', 'relationships', 'graph'] as const).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              tab === t
                ? 'border-folio-600 text-folio-600'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            {t.charAt(0).toUpperCase() + t.slice(1)}
          </button>
        ))}
      </div>

      {/* Columns tab */}
      {tab === 'columns' && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b text-left">
                <th className="px-3 py-2 font-semibold">Column</th>
                <th className="px-3 py-2 font-semibold">Type</th>
                <th className="px-3 py-2 font-semibold">Size</th>
                <th className="px-3 py-2 font-semibold">Nullable</th>
                <th className="px-3 py-2 font-semibold">FK</th>
              </tr>
            </thead>
            <tbody>
              {table.columns.map((col) => (
                <tr key={col.name} className="border-b hover:bg-gray-50">
                  <td className="px-3 py-2 font-mono text-xs">
                    {col.name === table.primary_key ? (
                      <span className="flex items-center gap-1">
                        <Key size={12} className="text-yellow-500" />
                        {col.name}
                      </span>
                    ) : (
                      col.name
                    )}
                  </td>
                  <td className="px-3 py-2 text-xs text-gray-600">{col.type}</td>
                  <td className="px-3 py-2 text-xs text-gray-400">{col.size || '—'}</td>
                  <td className="px-3 py-2 text-xs">
                    {col.nullable ? (
                      <span className="text-yellow-600">Yes</span>
                    ) : (
                      <span className="text-green-600">No</span>
                    )}
                  </td>
                  <td className="px-3 py-2 text-xs">
                    {col.parents?.map((p, i) => (
                      <button
                        key={i}
                        onClick={() => onNavigateTable(p.parent_table!)}
                        className="text-folio-600 hover:underline flex items-center gap-1"
                      >
                        <ArrowRight size={10} />
                        {p.parent_table}.{p.parent_column}
                      </button>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Relationships tab */}
      {tab === 'relationships' && (
        <div className="space-y-6">
          <div>
            <h3 className="font-semibold text-sm mb-2 text-gray-700">
              Parents (this table references)
            </h3>
            {relationships.parents.length === 0 ? (
              <p className="text-sm text-gray-400 italic">None</p>
            ) : (
              <div className="space-y-1">
                {relationships.parents.map((p, i) => (
                  <div
                    key={i}
                    className="flex items-center gap-2 text-sm bg-blue-50 rounded px-3 py-2"
                  >
                    <span className="font-mono text-xs">
                      {name}.{p.local_column}
                    </span>
                    <ArrowRight size={14} className="text-gray-400" />
                    <button
                      onClick={() => onNavigateTable(p.parent_table!)}
                      className="font-mono text-xs text-folio-600 hover:underline"
                    >
                      {p.parent_table}.{p.parent_column}
                    </button>
                    <span className="text-xs text-gray-400 ml-auto">
                      {p.foreign_key}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div>
            <h3 className="font-semibold text-sm mb-2 text-gray-700">
              Children (tables referencing this)
            </h3>
            {relationships.children.length === 0 ? (
              <p className="text-sm text-gray-400 italic">None</p>
            ) : (
              <div className="space-y-1">
                {relationships.children.map((c, i) => (
                  <div
                    key={i}
                    className="flex items-center gap-2 text-sm bg-yellow-50 rounded px-3 py-2"
                  >
                    <button
                      onClick={() => onNavigateTable(c.child_table!)}
                      className="font-mono text-xs text-folio-600 hover:underline"
                    >
                      {c.child_table}.{c.child_column}
                    </button>
                    <ArrowRight size={14} className="text-gray-400" />
                    <span className="font-mono text-xs">
                      {name}.{c.local_column}
                    </span>
                    <span className="text-xs text-gray-400 ml-auto">
                      {c.foreign_key}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {/* Graph tab */}
      {tab === 'graph' && (
        <div className="h-96 border rounded-lg">
          <JoinGraph
            tableName={name}
            relationships={relationships}
            onNavigateTable={onNavigateTable}
          />
        </div>
      )}
    </div>
  );
}
