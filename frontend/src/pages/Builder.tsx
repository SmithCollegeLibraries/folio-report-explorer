import { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
import {
  fetchSchema,
  fetchTableDetail,
  findPath,
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
import {
  currentRelationshipSelections,
  groupDirectRelationships,
  pruneRelationshipOverrides,
  type RelationshipOverrides,
} from '../components/builderRelationships';
import type {
  CanonicalQueryDefinition,
  CanonicalJoinEdge,
  RelationshipSelection,
  SelectedColumn,
  FilterCondition,
  SortSpec,
  JoinEdge,
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
const schemaIdentity = 'ldlite' as const;

function isCanonicalJoinEdge(edge: JoinEdge): edge is CanonicalJoinEdge {
  return typeof edge.relationship_id === 'string' && typeof edge.pair_id === 'string';
}

function buildDefaultSaveJoins(
  joinMode: 'auto' | 'manual',
  defaultJoins: CanonicalJoinEdge[],
  customJoins: CanonicalJoinEdge[],
): 'auto' | RelationshipSelection[] {
  if (joinMode === 'auto') return 'auto';

  const typeByPair = new Map(
    customJoins.map((join) => [join.pair_id, join.join_type]),
  );

  return defaultJoins.map((join) => {
    const joinType = typeByPair.get(join.pair_id) ?? join.join_type;
    return {
      relationship_id: join.relationship_id,
      ...(joinType ? { join_type: joinType } : {}),
    };
  });
}

export function resolvedJoinsForTopology(
  joins: CanonicalJoinEdge[],
  resolvedTopologySignature: string | null,
  currentTopologySignature: string,
): CanonicalJoinEdge[] {
  return resolvedTopologySignature === currentTopologySignature ? joins : [];
}

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
  const [customJoins, setCustomJoins] = useState<CanonicalJoinEdge[]>([]);
  const [defaultJoins, setDefaultJoins] = useState<CanonicalJoinEdge[]>([]);
  const [resolvedJoinTopologySignature, setResolvedJoinTopologySignature] = useState<string | null>(null);
  const [joinDiscoveryLoading, setJoinDiscoveryLoading] = useState(false);
  const [joinDiscoveryError, setJoinDiscoveryError] = useState<string | null>(null);
  const [activeRelationshipOverrides, setActiveRelationshipOverrides] = useState<RelationshipOverrides>({});
  const [relationshipNotice, setRelationshipNotice] = useState<string | null>(null);
  const [leftPanel, setLeftPanel] = useState<'browse' | 'relationships'>('browse');
  const [graphOpen, setGraphOpen] = useState(false);
  const queryVersionRef = useRef(0);
  const discoveryRequestRef = useRef(0);
  const buildRequestRef = useRef(0);
  const saveInFlightRef = useRef(false);

  const invalidateQueryResult = useCallback(() => {
    queryVersionRef.current += 1;
    setBuilt(null);
    setEditedSql(null);
  }, []);

  // --- async job polling ---
  const { job, results, isRunning, error: jobError, cancel: cancelJob, reset: resetJob, elapsedSeconds } = useJobPolling(activeJobId);

  // --- data fetching ---
  const { data: schemaData, isLoading: schemaLoading } = useQuery({
    queryKey: ['schema', schemaIdentity],
    queryFn: () => fetchSchema(undefined, schemaIdentity),
  });

  // Fetch table details
  const [tableDetails, setTableDetails] = useState<Record<string, TableDetail>>({});
  const [tableDetailErrors, setTableDetailErrors] = useState<Record<string, string>>({});
  const fetchedRef = useRef<Set<string>>(new Set());
  const detailRequestVersionsRef = useRef<Record<string, number>>({});
  const selectedTablesRef = useRef(selectedTables);
  selectedTablesRef.current = selectedTables;

  const loadTableDetail = useCallback((table: string) => {
    if (fetchedRef.current.has(table)) return;

    fetchedRef.current.add(table);
    const requestVersion = (detailRequestVersionsRef.current[table] ?? 0) + 1;
    detailRequestVersionsRef.current[table] = requestVersion;
    setTableDetailErrors((previous) => {
      if (!(table in previous)) return previous;
      const next = { ...previous };
      delete next[table];
      return next;
    });

    fetchTableDetail(table, schemaIdentity).then((detail) => {
      if (detailRequestVersionsRef.current[table] !== requestVersion
          || !selectedTablesRef.current.includes(table)) {
        return;
      }
      setTableDetails((previous) => ({ ...previous, [table]: detail }));
    }).catch(() => {
      if (detailRequestVersionsRef.current[table] !== requestVersion) return;
      fetchedRef.current.delete(table);
      if (!selectedTablesRef.current.includes(table)) return;
      setTableDetailErrors((previous) => ({
        ...previous,
        [table]: `Could not load details for ${table}.`,
      }));
    });
  }, []);

  const forgetTableDetail = useCallback((table: string) => {
    detailRequestVersionsRef.current[table] =
      (detailRequestVersionsRef.current[table] ?? 0) + 1;
    fetchedRef.current.delete(table);
    setTableDetails((previous) => {
      if (!(table in previous)) return previous;
      const next = { ...previous };
      delete next[table];
      return next;
    });
    setTableDetailErrors((previous) => {
      if (!(table in previous)) return previous;
      const next = { ...previous };
      delete next[table];
      return next;
    });
  }, []);

  useEffect(() => {
    for (const t of selectedTables) {
      if (!tableDetails[t] && !fetchedRef.current.has(t)) {
        loadTableDetail(t);
      }
    }
  }, [loadTableDetail, selectedTables, tableDetails]);

  const relationshipGroups = useMemo(
    () => groupDirectRelationships(tableDetails, selectedTables),
    [selectedTables, tableDetails],
  );
  const selectedTableTopologySignature = selectedTables.join('\u001f');
  const resolvedDefaultJoins = resolvedJoinsForTopology(
    defaultJoins,
    resolvedJoinTopologySignature,
    selectedTableTopologySignature,
  );

  useEffect(() => {
    setDefaultJoins([]);
    setResolvedJoinTopologySignature(null);
    setJoinDiscoveryError(null);
    invalidateQueryResult();
    const discoveryVersion = ++discoveryRequestRef.current;

    if (selectedTables.length < 2) {
      setJoinDiscoveryLoading(false);
      setResolvedJoinTopologySignature(selectedTableTopologySignature);
      return;
    }

    let cancelled = false;
    setJoinDiscoveryLoading(true);

    async function discoverDefaultPath() {
      const joins: CanonicalJoinEdge[] = [];
      const joined = new Set<string>([selectedTables[0]]);
      let missingTarget: string | null = null;

      for (let index = 1; index < selectedTables.length; index += 1) {
        const target = selectedTables[index];
        if (joined.has(target)) continue;

        let bestPath: CanonicalJoinEdge[] | null = null;
        for (const source of Array.from(joined)) {
          try {
            const response = await findPath(source, target, false, 6, schemaIdentity);
            const candidate = response.path?.joins ?? [];
            if (candidate.length > 0 && (!bestPath || candidate.length < bestPath.length)) {
              bestPath = candidate;
            }
          } catch {
            // A different already-connected source may still provide a complete path.
          }
        }

        if (!bestPath) {
          missingTarget = target;
          break;
        }

        for (const edge of bestPath) {
          if (!joins.some((join) => join.relationship_id === edge.relationship_id)) {
            joins.push({ ...edge, join_type: 'JOIN' });
          }
          joined.add(edge.from_table);
          joined.add(edge.to_table);
        }
        joined.add(target);
      }

      if (cancelled || discoveryVersion !== discoveryRequestRef.current) return;
      invalidateQueryResult();
      setDefaultJoins(missingTarget ? [] : joins);
      setResolvedJoinTopologySignature(selectedTableTopologySignature);
      setJoinDiscoveryError(missingTarget ? `Cannot find FK path to "${missingTarget}"` : null);
      setJoinDiscoveryLoading(false);
    }

    void discoverDefaultPath();
    return () => { cancelled = true; };
  }, [selectedTableTopologySignature, invalidateQueryResult]);

  const selectRelationship = useCallback((pairId: string, relationshipId: string) => {
    setActiveRelationshipOverrides((current) => {
      const group = relationshipGroups[pairId];
      const next = { ...current };
      if (!group || relationshipId === group.defaultRelationshipId) {
        delete next[pairId];
      } else if (group.relationships.some((item) => item.relationship_id === relationshipId)) {
        next[pairId] = relationshipId;
      }
      return next;
    });
    invalidateQueryResult();
  }, [relationshipGroups]);

  useEffect(() => {
    const pruned = pruneRelationshipOverrides(
      activeRelationshipOverrides,
      selectedTables,
      relationshipGroups,
    );
    if (Object.keys(pruned).length !== Object.keys(activeRelationshipOverrides).length) {
      setActiveRelationshipOverrides(pruned);
      setRelationshipNotice('A selected table link is no longer available. Query Builder restored the default link.');
      invalidateQueryResult();
    }
  }, [selectedTables, relationshipGroups, activeRelationshipOverrides, invalidateQueryResult]);

  const activeJoinSelections = useMemo(
    () => currentRelationshipSelections(
      resolvedDefaultJoins,
      relationshipGroups,
      activeRelationshipOverrides,
      customJoins,
    ),
    [resolvedDefaultJoins, relationshipGroups, activeRelationshipOverrides, customJoins],
  );

  const joinsForBuild = useMemo<CanonicalQueryDefinition['joins']>(() => {
    const hasOverride = Object.keys(activeRelationshipOverrides).length > 0;
    if (resolvedDefaultJoins.length > 0 && (hasOverride || joinMode === 'manual')) {
      return activeJoinSelections;
    }
    return 'auto';
  }, [activeJoinSelections, activeRelationshipOverrides, customJoins, resolvedDefaultJoins, joinMode]);

  const handleCustomJoinsChange = useCallback((joins: JoinEdge[]) => {
    const canonical = joins.filter(isCanonicalJoinEdge);
    setCustomJoins(canonical);
    invalidateQueryResult();
  }, [invalidateQueryResult]);

  const resetRelationships = useCallback(() => {
    setActiveRelationshipOverrides({});
    invalidateQueryResult();
  }, [invalidateQueryResult]);

  // Clear edited SQL when build changes
  useEffect(() => {
    setEditedSql(null);
  }, [built]);

  const createQueryDefinition = (joins: CanonicalQueryDefinition['joins']): CanonicalQueryDefinition => {
    const hasAggregates = columns.some((column) => Boolean(column.aggregate));
    const groupBy = hasAggregates
      ? columns
        .filter((column) => !column.aggregate)
        .map((column) => ({ table: column.table, column: column.column }))
      : undefined;

    return {
      schemaIdentity,
      tables: selectedTables,
      columns,
      filters,
      joins,
      orderBy,
      limit,
      distinct,
      ...(groupBy && groupBy.length > 0 ? { groupBy } : {}),
    };
  };

  const effectiveSql = editedSql ?? built?.sql ?? '';

  // --- mutations ---
  const buildMut = useMutation({
    mutationFn: ({ definition }: { definition: CanonicalQueryDefinition; version: number; requestId: number }) => buildQuery(definition),
    onSuccess: (data: BuildResponse, request) => {
      if (request.version !== queryVersionRef.current || request.requestId !== buildRequestRef.current) {
        return;
      }
      setBuilt(data);
      setActiveTab('sql');
      resetJob();
      setActiveJobId(null);
    },
  });

  const startBuild = useCallback(() => {
    const requestId = ++buildRequestRef.current;
    buildMut.mutate({
      definition: createQueryDefinition(joinsForBuild),
      version: queryVersionRef.current,
      requestId,
    });
  }, [buildMut, joinsForBuild, selectedTables, columns, filters, orderBy, limit, distinct]);

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

  type SaveSnapshot = {
    name: string;
    description: string;
    queryDefinition: CanonicalQueryDefinition;
    generatedSql: string;
    sqlEdited: boolean;
    rebuildDefault: boolean;
  };

  const saveMut = useMutation({
    mutationFn: async (snapshot: SaveSnapshot) => {
      let generatedSql = snapshot.generatedSql;
      if (snapshot.rebuildDefault) {
        try {
          generatedSql = (await buildQuery(snapshot.queryDefinition)).sql;
        } catch {
          throw new Error('Could not rebuild the default joins. The query was not saved.');
        }
      }

      return saveQuery({
        name: snapshot.name,
        description: snapshot.description,
        queryDefinition: snapshot.queryDefinition,
        generatedSql,
        sqlEdited: snapshot.sqlEdited,
      });
    },
    onSuccess: () => {
      setSaveOpen(false);
      setSaveName('');
      setSaveDesc('');
    },
    onSettled: () => {
      saveInFlightRef.current = false;
    },
  });

  const startSave = useCallback(() => {
    if (saveInFlightRef.current || !saveName) return;
    saveInFlightRef.current = true;
    const rebuildDefault = Object.keys(activeRelationshipOverrides).length > 0;
    saveMut.mutate({
      name: saveName,
      description: saveDesc,
      queryDefinition: createQueryDefinition(
        rebuildDefault
          ? buildDefaultSaveJoins(joinMode, resolvedDefaultJoins, customJoins)
          : joinsForBuild,
      ),
      generatedSql: effectiveSql,
      sqlEdited: !rebuildDefault && editedSql !== null,
      rebuildDefault,
    });
  }, [saveName, saveDesc, activeRelationshipOverrides, joinMode, resolvedDefaultJoins, customJoins, joinsForBuild, effectiveSql, editedSql, saveMut]);

  // --- handlers ---
  const toggleTable = useCallback(
    (table: string) => {
      if (selectedTables.includes(table)) {
        // Remove table + its columns, filters, sorts
        forgetTableDetail(table);
        setSelectedTables((prev) => prev.filter((t) => t !== table));
        setColumns((prev) => prev.filter((c) => c.table !== table));
        setFilters((prev) => prev.filter((f) => f.table !== table));
        setOrderBy((prev) => prev.filter((s) => s.table !== table));
      } else {
        setSelectedTables((prev) => [...prev, table]);
      }
      invalidateQueryResult();
      resetJob();
      setActiveJobId(null);
    },
    [selectedTables, resetJob, forgetTableDetail],
  );

  const removeTable = useCallback(
    (table: string) => {
      forgetTableDetail(table);
      setSelectedTables((prev) => prev.filter((t) => t !== table));
      setColumns((prev) => prev.filter((c) => c.table !== table));
      setFilters((prev) => prev.filter((f) => f.table !== table));
      setOrderBy((prev) => prev.filter((s) => s.table !== table));
      invalidateQueryResult();
      resetJob();
      setActiveJobId(null);
    },
    [resetJob, forgetTableDetail],
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

  const joinTopologyReady = selectedTables.length < 2
    || (resolvedJoinTopologySignature === selectedTableTopologySignature
      && !joinDiscoveryLoading
      && joinDiscoveryError === null
      && resolvedDefaultJoins.length > 0);
  const canBuild = selectedTables.length > 0 && columns.length > 0 && joinTopologyReady;
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
          onClick={() => { setDistinct((d) => !d); invalidateQueryResult(); }}
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
                onClick={() => { setLimit(n); invalidateQueryResult(); }}
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
              onChange={(e) => { setLimit(Number(e.target.value) || 100); invalidateQueryResult(); }}
              className="w-16 text-xs border rounded px-1.5 py-1 text-center"
            />
          </div>
        </div>

        {/* Action buttons */}
        <div className="flex gap-2">
          <button
            onClick={startBuild}
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
        {Object.entries(tableDetailErrors).length > 0 && (
          <div
            role="alert"
            className="absolute left-4 top-16 z-20 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 shadow-sm"
          >
            {Object.entries(tableDetailErrors).map(([table, message]) => (
              <div key={table}>
                {message}{' '}
                <button
                  type="button"
                  className="underline"
                  onClick={() => loadTableDetail(table)}
                  aria-label={`Retry ${table} details`}
                >
                  Retry
                </button>
              </div>
            ))}
          </div>
        )}
        {relationshipNotice && (
          <div
            role="status"
            className="absolute right-4 top-16 z-20 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 shadow-sm"
          >
            {relationshipNotice}
            <button
              type="button"
              className="ml-2 underline"
              onClick={() => setRelationshipNotice(null)}
            >
              Dismiss
            </button>
          </div>
        )}
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
                  onColumnsChange={(cols) => { setColumns(cols); invalidateQueryResult(); }}
                />
              )}
              {activeTab === 'filters' && (
                <FilterPanel
                  tableColumns={tableColumnsMap}
                  filters={filters}
                  onFiltersChange={(f) => { setFilters(f); invalidateQueryResult(); }}
                />
              )}
              {activeTab === 'sort' && (
                <SortPanel
                  tableColumns={tableColumnsMap}
                  orderBy={orderBy}
                  onOrderByChange={(s) => { setOrderBy(s); invalidateQueryResult(); }}
                />
              )}
              {activeTab === 'joins' && (
                <JoinPanel
                  selectedTables={selectedTables}
                  joinMode={joinMode}
                  customJoins={customJoins}
                  onJoinModeChange={(m) => { setJoinMode(m); invalidateQueryResult(); }}
                  onCustomJoinsChange={handleCustomJoinsChange}
                  defaultJoins={resolvedDefaultJoins}
                  discoveryLoading={joinDiscoveryLoading}
                  discoveryError={joinDiscoveryError}
                  relationshipGroups={relationshipGroups}
                  activeRelationshipOverrides={activeRelationshipOverrides}
                  onRelationshipChange={selectRelationship}
                  onResetRelationships={resetRelationships}
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
                relationshipGroups={relationshipGroups}
                activeRelationshipOverrides={activeRelationshipOverrides}
                onRelationshipChange={selectRelationship}
              />
            </div>
          </div>
        </div>
      )}

      {/* ─── Save dialog ─── */}
      {saveOpen && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50">
          <div
            role="dialog"
            aria-labelledby="save-query-heading"
            className="bg-white rounded-lg shadow-xl p-6 w-96"
          >
            <h3 id="save-query-heading" className="font-semibold mb-4">Save Query</h3>
            {Object.keys(activeRelationshipOverrides).length > 0 && (
              <p className="mb-3 text-sm text-amber-800" role="status">
                Alternate joins apply to this session only. Saved queries use the default table links.
              </p>
            )}
            {Object.keys(activeRelationshipOverrides).length > 0 && editedSql !== null && (
              <p className="mb-3 text-sm text-amber-800" role="status">
                Session SQL edits are not persisted when an alternate table link is active.
              </p>
            )}
            <input
              placeholder="Query name"
              value={saveName}
              onChange={(e) => setSaveName(e.target.value)}
              disabled={saveMut.isPending}
              className="border rounded w-full px-3 py-2 mb-3 text-sm"
            />
            <textarea
              placeholder="Description (optional)"
              value={saveDesc}
              onChange={(e) => setSaveDesc(e.target.value)}
              disabled={saveMut.isPending}
              className="border rounded w-full px-3 py-2 mb-3 text-sm h-20 resize-none"
            />
            {saveMut.isError && (
              <p className="mb-3 text-sm text-red-700" role="alert">
                {saveMut.error instanceof Error ? saveMut.error.message : String(saveMut.error)}
              </p>
            )}
            <div className="flex justify-end gap-2">
              <button
                onClick={() => {
                  if (!saveInFlightRef.current) setSaveOpen(false);
                }}
                disabled={saveMut.isPending}
                className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={startSave}
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
