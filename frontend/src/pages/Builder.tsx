import { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
import {
  fetchSchema,
  fetchTableDetail,
  buildQuery,
  submitQuery,
  saveQuery,
  downloadExportCsv,
} from '../api/client';
import { useJobPolling } from '../hooks/useJobPolling';
import RelationshipPanel from '../components/RelationshipPanel';
import BuilderGraph from '../components/BuilderGraph';
import TableBrowser from '../components/TableBrowser';
import ColumnPicker from '../components/ColumnPicker';
import FilterPanel from '../components/FilterPanel';
import SortPanel from '../components/SortPanel';
import JoinPanel from '../components/JoinPanel';
import SqlPreview from '../components/SqlPreview';
import ResultsTable from '../components/ResultsTable';
import type {
  QueryDefinition,
  SelectedColumn,
  FilterCondition,
  SortSpec,
  JoinEdge,
  GroupBySpec,
  BuildResponse,
  TableDetail,
} from '../types';
import {
  Play, Save, Square, Loader2, Columns3, Filter, ArrowUpDown, Code2, Link2,
  ToggleLeft, ToggleRight, List, GitFork, ChevronRight, X,
} from 'lucide-react';

type Tab = 'columns' | 'filters' | 'joins' | 'sort' | 'sql';

const TABS: { key: Tab; label: string; icon: React.ReactNode }[] = [
  { key: 'columns', label: 'Columns', icon: <Columns3 size={14} /> },
  { key: 'filters', label: 'Filters', icon: <Filter size={14} /> },
  { key: 'joins', label: 'Joins', icon: <Link2 size={14} /> },
  { key: 'sort', label: 'Sort', icon: <ArrowUpDown size={14} /> },
  { key: 'sql', label: 'SQL', icon: <Code2 size={14} /> },
];

const LIMIT_PRESETS = [10, 100, 500, 1000];

export default function Builder() {
  // --- state ---
  const [selectedTables, setSelectedTables] = useState<string[]>([]);
  const [columns, setColumns] = useState<SelectedColumn[]>([]);
  const [filters, setFilters] = useState<FilterCondition[]>([]);
  const [orderBy, setOrderBy] = useState<SortSpec[]>([]);
  const [limit, setLimit] = useState(100);
  const [distinct, setDistinct] = useState(false);
  const [built, setBuilt] = useState<BuildResponse | null>(null);
  const [activeTab, setActiveTab] = useState<Tab>('columns');
  const [sqlEditable, setSqlEditable] = useState(false);
  const [editedSql, setEditedSql] = useState<string | null>(null);
  const [saveOpen, setSaveOpen] = useState(false);
  const [saveName, setSaveName] = useState('');
  const [saveDesc, setSaveDesc] = useState('');
  const [activeJobId, setActiveJobId] = useState<string | null>(null);
  const [joinMode, setJoinMode] = useState<'auto' | 'manual'>('auto');
  const [customJoins, setCustomJoins] = useState<JoinEdge[]>([]);
  const [leftPanel, setLeftPanel] = useState<'browse' | 'relationships'>('browse');
  const [graphOpen, setGraphOpen] = useState(false);

  // --- async job polling ---
  const { job, results, isRunning, error: jobError, cancel: cancelJob, reset: resetJob, elapsedSeconds } = useJobPolling(activeJobId);

  // --- data fetching ---
  const { data: schemaData, isLoading: schemaLoading } = useQuery({
    queryKey: ['schema'],
    queryFn: () => fetchSchema(),
  });

  // Fetch table details
  const [tableDetails, setTableDetails] = useState<Record<string, TableDetail>>({});
  const fetchedRef = useRef<Set<string>>(new Set());

  useEffect(() => {
    for (const t of selectedTables) {
      if (!tableDetails[t] && !fetchedRef.current.has(t)) {
        fetchedRef.current.add(t);
        fetchTableDetail(t).then((detail) => {
          setTableDetails((prev) => ({ ...prev, [t]: detail }));
        });
      }
    }
  }, [selectedTables]);

  // Clear edited SQL when build changes
  useEffect(() => {
    setEditedSql(null);
  }, [built]);

  // --- mutations ---
  const buildMut = useMutation({
    mutationFn: () => {
      // Auto-compute GROUP BY: if any column has an aggregate, group by all non-aggregated columns
      const hasAggregates = columns.some((c) => Boolean(c.aggregate));
      let groupBy: GroupBySpec[] | undefined;
      if (hasAggregates) {
        groupBy = columns
          .filter((c) => !c.aggregate)
          .map((c) => ({ table: c.table, column: c.column }));
      }

      const def: QueryDefinition = {
        tables: selectedTables,
        columns,
        filters,
        joins: joinMode === 'manual' && customJoins.length > 0 ? customJoins : 'auto',
        orderBy,
        limit,
        distinct,
        ...(groupBy && groupBy.length > 0 ? { groupBy } : {}),
      };
      return buildQuery(def);
    },
    onSuccess: (data: BuildResponse) => {
      setBuilt(data);
      setActiveTab('sql');
      resetJob();
      setActiveJobId(null);
    },
  });

  const execMut = useMutation({
    mutationFn: ({ sql, params, options }: { sql: string; params: Record<string, string>; options?: { confirmed?: boolean; outputMode?: 'table' | 'file' } }) => {
      const jobName = selectedTables.length > 0
        ? `Builder: ${selectedTables.slice(0, 3).join(', ')}${selectedTables.length > 3 ? ` +${selectedTables.length - 3} more` : ''}`
        : undefined;
      return submitQuery(sql, params, 'builder', jobName, 'folio', options);
    },
    onSuccess: (data, vars) => {
      if (data.requiresConfirmation) {
        const rowText = data.estimatedRows != null ? `~${data.estimatedRows.toLocaleString()} rows` : 'unknown row count';
        const costText = data.estimatedCost != null ? `${Math.round(data.estimatedCost).toLocaleString()} cost` : 'unknown cost';
        const shouldExport = window.confirm(
          `This query is estimated as large (${rowText}, ${costText}).\n\nClick OK to run as CSV export in the background.\nClick Cancel to run in-browser with normal row limits.`,
        );
        execMut.mutate({
          sql: vars.sql,
          params: vars.params,
          options: shouldExport ? { outputMode: 'file' } : { confirmed: true, outputMode: 'table' },
        });
        return;
      }
      if (data.jobId) {
        setActiveJobId(data.jobId);
      }
    },
  });

  const saveMut = useMutation({
    mutationFn: () =>
      saveQuery({
        name: saveName,
        description: saveDesc,
        queryDefinition: { tables: selectedTables, columns, filters, joins: joinMode === 'manual' && customJoins.length > 0 ? customJoins : 'auto', orderBy, limit, distinct },
        generatedSql: effectiveSql,
      }),
    onSuccess: () => {
      setSaveOpen(false);
      setSaveName('');
      setSaveDesc('');
    },
  });

  // --- handlers ---
  const toggleTable = useCallback(
    (table: string) => {
      if (selectedTables.includes(table)) {
        // Remove table + its columns, filters, sorts
        setSelectedTables((prev) => prev.filter((t) => t !== table));
        setColumns((prev) => prev.filter((c) => c.table !== table));
        setFilters((prev) => prev.filter((f) => f.table !== table));
        setOrderBy((prev) => prev.filter((s) => s.table !== table));
      } else {
        setSelectedTables((prev) => [...prev, table]);
      }
      setBuilt(null);
      resetJob();
      setActiveJobId(null);
    },
    [selectedTables, resetJob],
  );

  const removeTable = useCallback(
    (table: string) => {
      setSelectedTables((prev) => prev.filter((t) => t !== table));
      setColumns((prev) => prev.filter((c) => c.table !== table));
      setFilters((prev) => prev.filter((f) => f.table !== table));
      setOrderBy((prev) => prev.filter((s) => s.table !== table));
      setBuilt(null);
      resetJob();
      setActiveJobId(null);
    },
    [resetJob],
  );

  // --- derived ---
  const tableColumnsMap = useMemo(() => {
    const result: Record<string, any[]> = {};
    for (const t of selectedTables) {
      const detail = tableDetails[t];
      if (detail) result[t] = detail.table.columns;
    }
    return result;
  }, [selectedTables, tableDetails]);

  const effectiveSql = editedSql ?? built?.sql ?? '';

  const canBuild = selectedTables.length > 0 && columns.length > 0;
  const canRun = !!effectiveSql && !isRunning;

  if (schemaLoading) {
    return (
      <div className="flex items-center justify-center h-96 text-gray-500">
        Loading schema…
      </div>
    );
  }

  return (
    <div className="flex flex-col h-[calc(100vh-56px)]">
      {/* ─── Top bar ─── */}
      <div className="px-4 py-2 bg-white border-b flex items-center gap-3 flex-shrink-0">
        {/* Step indicators */}
        <div className="flex items-center gap-1 text-xs">
          <span className={`flex items-center gap-1 px-2 py-1 rounded-full font-medium ${
            selectedTables.length > 0
              ? 'bg-folio-100 text-folio-700'
              : 'bg-gray-100 text-gray-500'
          }`}>
            1. Tables{selectedTables.length > 0 && ` (${selectedTables.length})`}
          </span>
          <ChevronRight size={12} className="text-gray-300" />
          <span className={`flex items-center gap-1 px-2 py-1 rounded-full font-medium ${
            columns.length > 0
              ? 'bg-folio-100 text-folio-700'
              : 'bg-gray-100 text-gray-500'
          }`}>
            2. Columns{columns.length > 0 && ` (${columns.length})`}
          </span>
          <ChevronRight size={12} className="text-gray-300" />
          <span className={`flex items-center gap-1 px-2 py-1 rounded-full font-medium ${
            filters.length > 0
              ? 'bg-orange-100 text-orange-700'
              : 'bg-gray-100 text-gray-500'
          }`}>
            3. Filters{filters.length > 0 ? ` (${filters.length})` : ' (opt)'}
          </span>
          <ChevronRight size={12} className="text-gray-300" />
          <span className={`flex items-center gap-1 px-2 py-1 rounded-full font-medium ${
            effectiveSql
              ? 'bg-green-100 text-green-700'
              : 'bg-gray-100 text-gray-500'
          }`}>
            4. Run
          </span>
        </div>

        {/* Spacer */}
        <div className="flex-1" />

        {/* DISTINCT toggle */}
        <button
          onClick={() => { setDistinct((d) => !d); setBuilt(null); }}
          className={`flex items-center gap-1 text-xs px-2 py-1 rounded border ${
            distinct
              ? 'bg-folio-50 text-folio-700 border-folio-300'
              : 'text-gray-500 border-gray-200 hover:border-gray-300'
          }`}
          title="Toggle DISTINCT"
        >
          {distinct ? <ToggleRight size={14} /> : <ToggleLeft size={14} />}
          DISTINCT
        </button>

        {/* Limit */}
        <div className="flex items-center gap-1">
          <span className="text-xs text-gray-500">Limit:</span>
          <div className="flex gap-0.5">
            {LIMIT_PRESETS.map((n) => (
              <button
                key={n}
                onClick={() => { setLimit(n); setBuilt(null); }}
                className={`text-xs px-2 py-1 rounded border ${
                  limit === n
                    ? 'bg-folio-600 text-white border-folio-600'
                    : 'border-gray-200 hover:border-gray-300 text-gray-600'
                }`}
              >
                {n}
              </button>
            ))}
            <input
              type="number"
              min={1}
              max={50000}
              value={limit}
              onChange={(e) => { setLimit(Number(e.target.value) || 100); setBuilt(null); }}
              className="w-16 text-xs border rounded px-1.5 py-1 text-center"
            />
          </div>
        </div>

        {/* Action buttons */}
        <div className="flex gap-2">
          <button
            onClick={() => buildMut.mutate()}
            disabled={!canBuild || buildMut.isPending}
            className="flex items-center gap-1 bg-folio-600 text-white px-3 py-1.5 rounded text-sm hover:bg-folio-700 disabled:opacity-50"
          >
            {buildMut.isPending ? <Loader2 size={14} className="animate-spin" /> : <Code2 size={14} />}
            Build SQL
          </button>
          {canRun && (
            <button
              onClick={() => execMut.mutate({ sql: effectiveSql, params: built?.params ?? {} })}
              disabled={execMut.isPending}
              className="flex items-center gap-1 bg-green-600 text-white px-3 py-1.5 rounded text-sm hover:bg-green-700 disabled:opacity-50"
            >
              <Play size={14} />
              {execMut.isPending ? 'Submitting…' : 'Run'}
            </button>
          )}
          {isRunning && (
            <button
              onClick={cancelJob}
              className="flex items-center gap-1 bg-red-600 text-white px-3 py-1.5 rounded text-sm hover:bg-red-700"
            >
              <Square size={14} /> Cancel
            </button>
          )}
          {effectiveSql && (
            <button
              onClick={() => setSaveOpen(true)}
              className="flex items-center gap-1 border px-3 py-1.5 rounded text-sm hover:bg-gray-50"
            >
              <Save size={14} /> Save
            </button>
          )}
        </div>
      </div>

      {/* ─── Main area: two-column layout ─── */}
      <div className="flex-1 flex overflow-hidden">
        {/* ─── Left column: Table browser / Graph ─── */}
        <div className="w-80 border-r flex flex-col flex-shrink-0">
          {/* Panel toggle */}
          <div className="flex border-b bg-gray-50 flex-shrink-0">
            <button
              onClick={() => setLeftPanel('browse')}
              className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors ${
                leftPanel === 'browse'
                  ? 'bg-white text-folio-700 border-b-2 border-folio-600'
                  : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              <List size={13} /> Browse
            </button>
            <button
              onClick={() => setLeftPanel('relationships')}
              className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors ${
                leftPanel === 'relationships'
                  ? 'bg-white text-folio-700 border-b-2 border-folio-600'
                  : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              <GitFork size={13} /> Relationships
            </button>
          </div>

          {/* Panel content */}
          <div className="flex-1 overflow-hidden">
            {leftPanel === 'browse' ? (
              <TableBrowser
                tables={schemaData?.tables || {}}
                selectedTables={selectedTables}
                tableDetails={tableDetails}
                onAddTable={toggleTable}
                onRemoveTable={removeTable}
              />
            ) : (
              <RelationshipPanel
                selectedTables={selectedTables}
                tableDetails={tableDetails}
                tables={schemaData?.tables || {}}
                onShowGraph={() => setGraphOpen(true)}
              />
            )}
          </div>
        </div>

        {/* ─── Right column: Tabs + results ─── */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {/* Tab bar */}
          <div className="flex bg-white border-b flex-shrink-0">
            {TABS.map((tab) => (
              <button
                key={tab.key}
                onClick={() => setActiveTab(tab.key)}
                className={`flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                  activeTab === tab.key
                    ? 'border-folio-600 text-folio-700'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                }`}
              >
                {tab.icon}
                {tab.label}
                {/* Badges */}
                {tab.key === 'columns' && columns.length > 0 && (
                  <span className="ml-1 bg-folio-100 text-folio-700 text-xs px-1.5 rounded-full">
                    {columns.length}
                  </span>
                )}
                {tab.key === 'filters' && filters.length > 0 && (
                  <span className="ml-1 bg-orange-100 text-orange-700 text-xs px-1.5 rounded-full">
                    {filters.length}
                  </span>
                )}
                {tab.key === 'sort' && orderBy.length > 0 && (
                  <span className="ml-1 bg-purple-100 text-purple-700 text-xs px-1.5 rounded-full">
                    {orderBy.length}
                  </span>
                )}
                {tab.key === 'joins' && joinMode === 'manual' && (
                  <span className="ml-1 bg-amber-100 text-amber-700 text-xs px-1.5 rounded-full">
                    custom
                  </span>
                )}
              </button>
            ))}
          </div>

          {/* Tab content + results split */}
          <div className="flex-1 flex flex-col overflow-hidden">
            {/* Tab panel */}
            <div className="flex-shrink-0 max-h-[50vh] overflow-y-auto bg-white border-b">
              {activeTab === 'columns' && (
                <ColumnPicker
                  tableColumns={tableColumnsMap}
                  selectedColumns={columns}
                  onColumnsChange={(cols) => { setColumns(cols); setBuilt(null); }}
                />
              )}
              {activeTab === 'filters' && (
                <FilterPanel
                  tableColumns={tableColumnsMap}
                  filters={filters}
                  onFiltersChange={(f) => { setFilters(f); setBuilt(null); }}
                />
              )}
              {activeTab === 'sort' && (
                <SortPanel
                  tableColumns={tableColumnsMap}
                  orderBy={orderBy}
                  onOrderByChange={(s) => { setOrderBy(s); setBuilt(null); }}
                />
              )}
              {activeTab === 'joins' && (
                <JoinPanel
                  selectedTables={selectedTables}
                  tableDetails={tableDetails}
                  joinMode={joinMode}
                  customJoins={customJoins}
                  onJoinModeChange={(m) => { setJoinMode(m); setBuilt(null); }}
                  onCustomJoinsChange={(j) => { setCustomJoins(j); setBuilt(null); }}
                />
              )}
              {activeTab === 'sql' && (
                <div className="p-4">
                  {effectiveSql ? (
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <h3 className="text-sm font-semibold text-gray-700">
                          {sqlEditable ? 'Editable SQL' : 'Generated SQL'}
                        </h3>
                        <button
                          onClick={() => {
                            setSqlEditable((e) => !e);
                            if (!sqlEditable) setEditedSql(effectiveSql);
                          }}
                          className={`flex items-center gap-1 text-xs px-2 py-1 rounded border ${
                            sqlEditable
                              ? 'bg-amber-50 text-amber-700 border-amber-300'
                              : 'text-gray-500 border-gray-200 hover:border-gray-300'
                          }`}
                        >
                          {sqlEditable ? <ToggleRight size={14} /> : <ToggleLeft size={14} />}
                          Edit SQL
                        </button>
                      </div>
                      <SqlPreview
                        sql={effectiveSql}
                        readOnly={!sqlEditable}
                        onChange={(val) => setEditedSql(val)}
                        height="200px"
                      />
                      {built?.warnings && built.warnings.length > 0 && (
                        <div className="mt-2 text-xs text-yellow-600 space-y-1">
                          {built.warnings.map((w, i) => (
                            <div key={i}>⚠ {w}</div>
                          ))}
                        </div>
                      )}
                    </div>
                  ) : (
                    <div className="py-12 text-center text-gray-400 text-sm">
                      {canBuild
                        ? 'Click "Build SQL" to generate your query.'
                        : 'Select tables and columns first, then build SQL.'}
                    </div>
                  )}
                </div>
              )}

              {/* Empty states for columns/filters/sort tabs when no tables */}
              {activeTab !== 'sql' && selectedTables.length === 0 && (
                <div className="p-6 text-center text-gray-400 text-sm">
                  Select tables from the left panel to get started.
                </div>
              )}
            </div>

            {/* Errors */}
            {buildMut.isError && (
              <div className="mx-4 mt-2 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700 flex-shrink-0">
                Build error: {String(buildMut.error)}
              </div>
            )}
            {execMut.isError && (
              <div className="mx-4 mt-2 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700 flex-shrink-0">
                Submit error: {String(execMut.error)}
              </div>
            )}
            {jobError && (
              <div className="mx-4 mt-2 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700 flex-shrink-0">
                Execution error: {jobError}
              </div>
            )}

            {/* Job progress */}
            {isRunning && job && (
              <div className="mx-4 mt-2 p-4 bg-blue-50 border border-blue-200 rounded-xl flex-shrink-0">
                <div className="flex items-start gap-3">
                  <Loader2 size={18} className="animate-spin text-blue-600 mt-0.5 flex-shrink-0" />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold text-blue-800">
                      {job.status === 'pending'
                        ? 'Queued — waiting for worker…'
                        : job.status === 'pending_export'
                          ? 'Queued for CSV export…'
                          : 'Running query…'}
                    </div>
                    <div className="text-sm text-blue-600 mt-0.5">
                      Elapsed: <span className="font-mono font-medium">
                        {elapsedSeconds < 60 ? `${elapsedSeconds}s` : `${Math.floor(elapsedSeconds / 60)}m ${elapsedSeconds % 60}s`}
                      </span>
                      {elapsedSeconds >= 60 && (
                        <span className="ml-2 text-amber-600 font-medium">— large query, please wait…</span>
                      )}
                    </div>
                    <div className="mt-2 text-xs text-blue-500">
                      You can navigate away — the query will keep running.{' '}
                      <Link to="/history" className="underline font-medium hover:text-blue-700">Check History →</Link>
                    </div>
                  </div>
                  <button
                    onClick={cancelJob}
                    className="flex items-center gap-1 text-xs text-red-600 hover:text-red-800 border border-red-200 bg-white rounded px-2 py-1 flex-shrink-0"
                    title="Cancel query"
                  >
                    <Square size={11} /> Cancel
                  </button>
                </div>
              </div>
            )}

            {/* Results */}
            {results && (
              <div className="flex-1 overflow-auto p-4">
                {results.outputMode === 'file' ? (
                  <div className="border border-blue-200 bg-blue-50 rounded-lg p-4 text-sm text-blue-800">
                    <div className="font-medium mb-1">Export complete</div>
                    <div className="mb-3">
                      {results.columns.length > 0 && results.rows.length > 0
                        ? 'Preview shown below. Download CSV for the full result set.'
                        : 'This query was exported as CSV in the background. Download the file to view all data.'}
                    </div>
                    {results.downloadUrl && activeJobId && (
                      <button
                        onClick={() => downloadExportCsv(activeJobId)}
                        className="inline-flex items-center gap-1 px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700 text-xs"
                      >
                        Download full CSV
                      </button>
                    )}
                    {results.columns.length > 0 && results.rows.length > 0 && (
                      <div className="mt-4">
                        <ResultsTable data={results} />
                      </div>
                    )}
                  </div>
                ) : (
                  <ResultsTable data={results} />
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* ─── Relationship Graph modal ─── */}
      {graphOpen && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-6">
          <div className="bg-white rounded-xl shadow-2xl w-full h-full max-w-[95vw] max-h-[90vh] flex flex-col overflow-hidden">
            {/* Header */}
            <div className="flex items-center justify-between px-5 py-3 border-b bg-gray-50">
              <div>
                <h3 className="font-semibold text-gray-800">Relationship Graph</h3>
                <p className="text-xs text-gray-500 mt-0.5">
                  {selectedTables.length} table{selectedTables.length !== 1 ? 's' : ''} selected
                  — click gray nodes to add connected tables, double-click blue nodes to remove
                </p>
              </div>
              <button
                onClick={() => setGraphOpen(false)}
                className="p-1.5 hover:bg-gray-200 rounded-lg transition-colors"
              >
                <X size={18} className="text-gray-500" />
              </button>
            </div>
            {/* Graph */}
            <div className="flex-1">
              <BuilderGraph
                selectedTables={selectedTables}
                tableDetails={tableDetails}
                tables={schemaData?.tables || {}}
                onAddTable={toggleTable}
                onRemoveTable={removeTable}
              />
            </div>
          </div>
        </div>
      )}

      {/* ─── Save dialog ─── */}
      {saveOpen && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-xl p-6 w-96">
            <h3 className="font-semibold mb-4">Save Query</h3>
            <input
              placeholder="Query name"
              value={saveName}
              onChange={(e) => setSaveName(e.target.value)}
              className="border rounded w-full px-3 py-2 mb-3 text-sm"
            />
            <textarea
              placeholder="Description (optional)"
              value={saveDesc}
              onChange={(e) => setSaveDesc(e.target.value)}
              className="border rounded w-full px-3 py-2 mb-3 text-sm h-20 resize-none"
            />
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setSaveOpen(false)}
                className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={() => saveMut.mutate()}
                disabled={!saveName || saveMut.isPending}
                className="px-3 py-1.5 text-sm bg-folio-600 text-white rounded hover:bg-folio-700 disabled:opacity-50"
              >
                {saveMut.isPending ? 'Saving…' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
