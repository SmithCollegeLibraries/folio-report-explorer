import { useMemo, useCallback, useEffect, useRef, useState } from 'react';
import {
  ReactFlow,
  ReactFlowProvider,
  Background,
  Controls,
  type Node,
  type Edge,
  type EdgeMouseHandler,
  MarkerType,
  useNodesState,
  useEdgesState,
  useReactFlow,
  Panel,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import type { TableDetail, TableSummary } from '../types';
import {
  activeRelationship,
  type RelationshipGroups,
  type RelationshipOverrides,
} from './builderRelationships';
import BuilderRelationshipEdge, {
  type BuilderRelationshipEdgeData,
} from './BuilderRelationshipEdge';
import { layoutRelationshipGraph } from './builderGraphLayout';
import { reconcileUserArrangedNodes } from './builderGraphPositions';

interface ConnectedTable {
  name: string;
  connections: {
    fromTable: string;
    column: string;
    toColumn: string;
    direction: 'parent' | 'child';
  }[];
}

interface Props {
  selectedTables: string[];
  tableDetails: Record<string, TableDetail>;
  tables: Record<string, TableSummary>;
  onAddTable: (name: string) => void;
  onRemoveTable: (name: string) => void;
  relationshipGroups?: RelationshipGroups;
  activeRelationshipOverrides?: RelationshipOverrides;
  onRelationshipChange?: (pairId: string, relationshipId: string) => void;
}

function shortName(fullName: string): string {
  const dotIdx = fullName.indexOf('.');
  return dotIdx >= 0 ? fullName.substring(dotIdx + 1) : fullName;
}

function tablePairKey(left: string, right: string): string {
  return [left, right].sort().join('\u0000');
}

function graphTopologySignature(nodes: Node[], edges: Edge[]): string {
  return JSON.stringify({
    nodes: nodes.map((node) => node.id).sort(),
    edges: edges
      .map((edge) => JSON.stringify([edge.id, edge.source, edge.target]))
      .sort(),
  });
}

function graphNodePresentationSignature(nodes: Node[]): string {
  return JSON.stringify(nodes.map(({ id, type, data, style }) => ({ id, type, data, style })));
}

type LayoutMode = 'automatic' | 'user-arranged';
const emptyRelationshipGroups: RelationshipGroups = {};
const emptyRelationshipOverrides: RelationshipOverrides = {};
const ignoreRelationshipChange = () => undefined;

function BuilderGraphCanvas({
  selectedTables,
  tableDetails,
  tables,
  onAddTable,
  onRemoveTable,
  relationshipGroups = emptyRelationshipGroups,
  activeRelationshipOverrides = emptyRelationshipOverrides,
  onRelationshipChange = ignoreRelationshipChange,
}: Props) {
  // Compute connected tables
  const connectedTables = useMemo(() => {
    if (selectedTables.length === 0) return [];
    const selectedSet = new Set(selectedTables);
    const map = new Map<string, ConnectedTable['connections']>();

    for (const selTable of selectedTables) {
      const detail = tableDetails[selTable];
      if (!detail?.relationships) continue;

      for (const rel of detail.relationships.parents || []) {
        const parentName = rel.parent_table;
        if (!parentName || !tables[parentName] || selectedSet.has(parentName)) continue;
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
        if (!map.has(childName)) map.set(childName, []);
        map.get(childName)!.push({
          fromTable: selTable,
          column: rel.local_column,
          toColumn: rel.child_column || rel.local_column,
          direction: 'child',
        });
      }
    }

    const result: ConnectedTable[] = [];
    for (const [name, connections] of map) {
      result.push({ name, connections });
    }
    return result.sort((a, b) => b.connections.length - a.connections.length);
  }, [tableDetails, selectedTables, tables]);

  // Build nodes and edges
  const { graphNodes, graphEdges } = useMemo(() => {
    const nodes: Node[] = [];
    const edges: Edge[] = [];
    const selectedSet = new Set(selectedTables);

    // Selected table nodes
    for (const t of selectedTables) {
      nodes.push({
        id: t,
        data: { label: shortName(t), isSelected: true, tableName: t },
        position: { x: 60, y: 60 },
        type: 'default',
        style: {
          background: '#1e40af',
          color: 'white',
          border: '2px solid #1e3a8a',
          borderRadius: '10px',
          padding: '10px 18px',
          fontFamily: 'ui-monospace, monospace',
          fontSize: '13px',
          fontWeight: 'bold',
          minWidth: '160px',
          textAlign: 'center' as const,
          boxShadow: '0 2px 8px rgba(30,64,175,0.25)',
        },
      });
    }

    // Edges between selected tables (actual FK relationships)
    const edgeSet = new Set<string>();
    const directGroups = Object.values(relationshipGroups).filter((group) => (
      selectedSet.has(group.leftTable) && selectedSet.has(group.rightTable)
    ));
    const canonicalPairKeys = new Set(
      directGroups.map((group) => tablePairKey(group.leftTable, group.rightTable)),
    );
    for (const group of directGroups) {
      const active = activeRelationship(group, activeRelationshipOverrides) as typeof group.relationships[number] & {
        from_table: string;
        from_column: string;
        to_table: string;
        to_column: string;
      };
      if (!active.from_table || !active.to_table || !active.from_column || !active.to_column) continue;
      const label = `${active.from_column} → ${active.to_column}${
        group.relationships.length > 1 ? ` · ${group.relationships.length} links` : ''
      }`;
      edgeSet.add(group.pairId);
      edges.push({
        id: group.pairId,
        source: group.leftTable,
        target: group.rightTable,
        label,
        style: { strokeWidth: 2.5, stroke: '#3b82f6' },
        markerEnd: { type: MarkerType.ArrowClosed, color: '#3b82f6', width: 16, height: 16 },
        interactionWidth: 24,
        type: 'builderRelationship',
        data: {
          pairId: group.pairId,
          relationshipId: active.relationship_id,
          leftTable: group.leftTable,
          rightTable: group.rightTable,
          label,
          alternativeCount: group.relationships.length,
          isDefault: active.relationship_id === group.defaultRelationshipId,
        },
      });
    }

    // Retain legacy/local edges for pairs that are not represented by the canonical catalog.
    for (const t of selectedTables) {
      const detail = tableDetails[t];
      if (!detail?.relationships) continue;

      for (const rel of detail.relationships.parents || []) {
        if (!rel.parent_table || !selectedSet.has(rel.parent_table)) continue;
        if (canonicalPairKeys.has(tablePairKey(t, rel.parent_table))) continue;
        const edgeId = `${t}.${rel.local_column}->${rel.parent_table}.${rel.parent_column || 'id'}`;
        if (edgeSet.has(edgeId)) continue;
        edgeSet.add(edgeId);
        edges.push({
          id: edgeId,
          source: t,
          target: rel.parent_table,
          label: `${rel.local_column} → ${rel.parent_column || 'id'}`,
          style: { strokeWidth: 2.5, stroke: '#3b82f6' },
          markerEnd: { type: MarkerType.ArrowClosed, color: '#3b82f6', width: 16, height: 16 },
          type: 'smoothstep',
          labelStyle: { fontSize: 11, fill: '#1f2937', fontFamily: 'ui-monospace, monospace', fontWeight: 600 },
          labelBgPadding: [6, 4] as [number, number],
          labelBgStyle: { fill: '#eff6ff', fillOpacity: 0.95, stroke: '#bfdbfe', strokeWidth: 1, rx: 4 },
        });
      }
    }

    // Ghost nodes (connected but not selected)
    const maxGhosts = 10;
    connectedTables.slice(0, maxGhosts).forEach((ct) => {
      nodes.push({
        id: ct.name,
        data: { label: shortName(ct.name), isSelected: false, tableName: ct.name, connectionCount: ct.connections.length },
        position: { x: 60, y: 60 },
        type: 'default',
        style: {
          background: '#ffffff',
          color: '#6b7280',
          border: '2px dashed #9ca3af',
          borderRadius: '10px',
          padding: '10px 18px',
          fontFamily: 'ui-monospace, monospace',
          fontSize: '12px',
          minWidth: '150px',
          textAlign: 'center' as const,
          cursor: 'pointer',
        },
      });

      // Dashed edges from ghost to connected selected tables
      for (const conn of ct.connections) {
        const edgeId = `ghost:${ct.name}.${conn.column}<->${conn.fromTable}`;
        if (edgeSet.has(edgeId)) continue;
        edgeSet.add(edgeId);
        edges.push({
          id: edgeId,
          source: conn.direction === 'child' ? ct.name : conn.fromTable,
          target: conn.direction === 'child' ? conn.fromTable : ct.name,
          label: conn.column,
          style: { strokeWidth: 1.5, stroke: '#9ca3af', strokeDasharray: '6 4' },
          markerEnd: { type: MarkerType.ArrowClosed, color: '#9ca3af', width: 14, height: 14 },
          type: 'smoothstep',
          labelStyle: { fontSize: 10, fill: '#9ca3af', fontFamily: 'ui-monospace, monospace' },
          labelBgPadding: [4, 3] as [number, number],
          labelBgStyle: { fill: '#f9fafb', fillOpacity: 0.9, rx: 3 },
        });
      }
    });

    return { graphNodes: nodes, graphEdges: edges };
  }, [activeRelationshipOverrides, connectedTables, relationshipGroups, selectedTables, tableDetails]);

  const [nodes, setNodes, onNodesChange] = useNodesState(graphNodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(graphEdges);
  const [layoutMode, setLayoutMode] = useState<LayoutMode>('automatic');
  const layoutModeRef = useRef<LayoutMode>('automatic');
  const [layoutPending, setLayoutPending] = useState(false);
  const [layoutError, setLayoutError] = useState<string | null>(null);
  const layoutSequence = useRef(0);
  const layoutFrame = useRef<number | null>(null);
  const automaticTopologySignature = useRef<string | null>(null);
  const explicitRelayoutPending = useRef(false);
  const mounted = useRef(true);
  const latestGraphNodes = useRef(graphNodes);
  const latestGraphEdges = useRef(graphEdges);
  latestGraphNodes.current = graphNodes;
  latestGraphEdges.current = graphEdges;
  const topologySignature = useMemo(
    () => graphTopologySignature(graphNodes, graphEdges),
    [graphEdges, graphNodes],
  );
  const nodePresentationSignature = useMemo(
    () => graphNodePresentationSignature(graphNodes),
    [graphNodes],
  );
  const installedNodePresentationSignature = useRef(nodePresentationSignature);
  const { fitView } = useReactFlow();
  const graphContainerRef = useRef<HTMLDivElement>(null);
  const selectorRef = useRef<HTMLDivElement>(null);
  const selectorTriggerRef = useRef<HTMLButtonElement | null>(null);
  const [selectedPairId, setSelectedPairId] = useState<string | null>(null);

  const closeRelationshipSelector = useCallback((restoreFocus = true) => {
    setSelectedPairId(null);
    if (restoreFocus) {
      const trigger = selectorTriggerRef.current;
      if (trigger?.isConnected) trigger.focus();
    }
  }, []);

  const openRelationshipSelector = useCallback((
    pairId: string,
    trigger?: HTMLButtonElement | null,
  ) => {
    const group = relationshipGroups[pairId];
    if (!group || group.relationships.length <= 1) return;
    selectorTriggerRef.current = trigger ?? null;
    setSelectedPairId(pairId);
  }, [relationshipGroups]);

  const edgeTypes = useMemo(() => ({ builderRelationship: BuilderRelationshipEdge }), []);

  const edgesWithCallbacks = useMemo(() => edges.map((edge) => {
    if (edge.type !== 'builderRelationship' || !edge.data) return edge;
    return {
      ...edge,
      data: {
        ...(edge.data as Omit<BuilderRelationshipEdgeData, 'onChoose'>),
        onChoose: openRelationshipSelector,
      } as BuilderRelationshipEdgeData,
    };
  }), [edges, openRelationshipSelector]);

  const selectedRelationshipGroup = selectedPairId ? relationshipGroups[selectedPairId] : undefined;

  useEffect(() => {
    if (selectedPairId && (!selectedRelationshipGroup || selectedRelationshipGroup.relationships.length <= 1)) {
      closeRelationshipSelector();
    }
  }, [closeRelationshipSelector, selectedPairId, selectedRelationshipGroup]);

  useEffect(() => {
    if (!selectedPairId) return;
    selectorRef.current?.querySelector<HTMLButtonElement>('[aria-pressed="true"]')?.focus();
  }, [selectedPairId]);

  useEffect(() => {
    if (!selectedPairId) return undefined;
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeRelationshipSelector();
      }
    };
    const handleOutsideClick = (event: MouseEvent) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (selectorRef.current?.contains(target) || selectorTriggerRef.current?.contains(target)) return;
      closeRelationshipSelector();
    };
    document.addEventListener('keydown', handleKeyDown);
    document.addEventListener('click', handleOutsideClick);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.removeEventListener('click', handleOutsideClick);
    };
  }, [closeRelationshipSelector, selectedPairId]);

  const cancelScheduledFit = useCallback(() => {
    if (layoutFrame.current === null) return;
    cancelAnimationFrame(layoutFrame.current);
    layoutFrame.current = null;
  }, []);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      layoutSequence.current += 1;
      automaticTopologySignature.current = null;
      explicitRelayoutPending.current = false;
      cancelScheduledFit();
    };
  }, [cancelScheduledFit]);

  const runAutomaticLayout = useCallback(async (
    nextNodes: Node[],
    nextEdges: Edge[],
    resetMode: boolean,
  ) => {
    cancelScheduledFit();
    const sequence = ++layoutSequence.current;
    setLayoutPending(true);
    setLayoutError(null);
    try {
      const result = await layoutRelationshipGraph({
        nodes: nextNodes,
        edges: nextEdges,
        direction: 'RIGHT',
      });
      if (!mounted.current || sequence !== layoutSequence.current) return;
      setNodes((current) => {
        const currentById = new Map(current.map((node) => [node.id, node]));
        const resultPositions = new Map(result.nodes.map((node) => [node.id, node.position]));
        const promotedPositions = new Map(
          latestGraphNodes.current
            .filter((node) => (
              node.data.isSelected === true
              && currentById.get(node.id)?.data.isSelected === false
            ))
            .map((node) => [node.id, currentById.get(node.id)!.position]),
        );
        return latestGraphNodes.current.map((node) => ({
          ...node,
          position: promotedPositions.get(node.id) ?? resultPositions.get(node.id) ?? node.position,
        }));
      });
      const resultEdgeTypes = new Map(result.edges.map((edge) => [edge.id, edge.type]));
      setEdges(latestGraphEdges.current.map((edge) => ({
        ...edge,
        type: edge.type === 'builderRelationship'
          ? edge.type
          : resultEdgeTypes.get(edge.id) ?? edge.type,
      })));
      if (resetMode) {
        explicitRelayoutPending.current = false;
        layoutModeRef.current = 'automatic';
        setLayoutMode('automatic');
      }
      let frame = 0;
      frame = requestAnimationFrame(() => {
        if (layoutFrame.current === frame) layoutFrame.current = null;
        if (!mounted.current || sequence !== layoutSequence.current) return;
        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
        void fitView({ padding: 0.4, maxZoom: 1.2, duration: reduceMotion ? 0 : 250 });
      });
      layoutFrame.current = frame;
    } catch {
      if (!mounted.current || sequence !== layoutSequence.current) return;
      setLayoutError('Could not arrange this graph');
      setNodes((current) => reconcileUserArrangedNodes(
        latestGraphNodes.current,
        current,
        latestGraphEdges.current,
      ));
      setEdges(latestGraphEdges.current);
      if (resetMode) explicitRelayoutPending.current = false;
    } finally {
      if (mounted.current && sequence === layoutSequence.current) setLayoutPending(false);
    }
  }, [cancelScheduledFit, fitView, setEdges, setNodes]);

  useEffect(() => {
    if (layoutModeRef.current === 'automatic' || explicitRelayoutPending.current) {
      if (automaticTopologySignature.current !== topologySignature) {
        automaticTopologySignature.current = topologySignature;
        installedNodePresentationSignature.current = nodePresentationSignature;
        void runAutomaticLayout(graphNodes, graphEdges, explicitRelayoutPending.current);
        return;
      }
      if (installedNodePresentationSignature.current !== nodePresentationSignature) {
        installedNodePresentationSignature.current = nodePresentationSignature;
        setNodes((current) => reconcileUserArrangedNodes(graphNodes, current, graphEdges));
      }
      setEdges(graphEdges);
      return;
    }
    layoutSequence.current += 1;
    cancelScheduledFit();
    setLayoutPending(false);
    if (
      automaticTopologySignature.current !== topologySignature
      || installedNodePresentationSignature.current !== nodePresentationSignature
    ) {
      automaticTopologySignature.current = topologySignature;
      installedNodePresentationSignature.current = nodePresentationSignature;
      setNodes((current) => reconcileUserArrangedNodes(graphNodes, current, graphEdges));
    }
    setEdges(graphEdges);
  }, [
    cancelScheduledFit,
    graphEdges,
    graphNodes,
    nodePresentationSignature,
    runAutomaticLayout,
    setEdges,
    setNodes,
    topologySignature,
  ]);

  const onNodeDragStop = useCallback(() => {
    layoutSequence.current += 1;
    cancelScheduledFit();
    explicitRelayoutPending.current = false;
    layoutModeRef.current = 'user-arranged';
    setLayoutMode('user-arranged');
    setLayoutPending(false);
    setLayoutError(null);
    setNodes((current) => reconcileUserArrangedNodes(graphNodes, current, graphEdges));
    setEdges(graphEdges);
  }, [cancelScheduledFit, graphEdges, graphNodes, setEdges, setNodes]);

  const onNodeClick = useCallback(
    (_: React.MouseEvent, node: Node) => {
      const data = node.data as { isSelected?: boolean; tableName?: string };
      if (data.tableName && !data.isSelected) {
        onAddTable(data.tableName as string);
      }
    },
    [onAddTable],
  );

  const onNodeDoubleClick = useCallback(
    (_: React.MouseEvent, node: Node) => {
      const data = node.data as { isSelected?: boolean; tableName?: string };
      if (data.tableName && data.isSelected) {
        onRemoveTable(data.tableName as string);
      }
    },
    [onRemoveTable],
  );

  const onEdgeClick = useCallback<EdgeMouseHandler>((_event, edge) => {
    const pairId = edge.data?.pairId;
    if (typeof pairId !== 'string') return;
    const trigger = Array.from(
      graphContainerRef.current?.querySelectorAll<HTMLButtonElement>('[data-relationship-pair-id]') ?? [],
    ).find((candidate) => candidate.dataset.relationshipPairId === pairId);
    openRelationshipSelector(pairId, trigger);
  }, [openRelationshipSelector]);

  return (
    <div ref={graphContainerRef} className="builder-relationship-graph h-full w-full relative">
      <ReactFlow
        nodes={nodes}
        edges={edgesWithCallbacks}
        edgeTypes={edgeTypes}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onNodeClick={onNodeClick}
        onNodeDoubleClick={onNodeDoubleClick}
        onEdgeClick={onEdgeClick}
        onNodeDragStop={onNodeDragStop}
        proOptions={{ hideAttribution: true }}
        minZoom={0.2}
        maxZoom={2.5}
        defaultEdgeOptions={{ animated: false }}
      >
        <Background gap={24} size={1} color="#e5e7eb" />
        <Controls showInteractive={false} />

        {selectedRelationshipGroup && (
          <Panel position="top-center">
            <div
              ref={selectorRef}
              role="dialog"
              aria-label="Choose relationship"
              className="w-80 rounded-lg border border-gray-200 bg-white p-3 shadow-xl"
            >
              <div className="mb-2 flex items-start justify-between gap-3">
                <div>
                  <h4 className="text-sm font-semibold text-gray-800">Choose relationship</h4>
                  <p className="mt-0.5 text-[11px] text-gray-500">
                    {selectedRelationshipGroup.leftTable} ↔ {selectedRelationshipGroup.rightTable}
                  </p>
                </div>
                <button
                  type="button"
                  aria-label="Close relationship selector"
                  onClick={() => closeRelationshipSelector()}
                  className="rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100"
                >
                  Close
                </button>
              </div>
              <div className="space-y-1">
                {selectedRelationshipGroup.relationships.map((relationship) => {
                  const isDefault = relationship.relationship_id === selectedRelationshipGroup.defaultRelationshipId;
                  const isActive = relationship.relationship_id === activeRelationship(
                    selectedRelationshipGroup,
                    activeRelationshipOverrides,
                  ).relationship_id;
                  return (
                    <button
                      key={relationship.relationship_id}
                      type="button"
                      aria-pressed={isActive}
                      onClick={() => {
                        onRelationshipChange(selectedRelationshipGroup.pairId, relationship.relationship_id);
                        closeRelationshipSelector();
                      }}
                      className={`flex w-full items-center justify-between rounded border px-3 py-2 text-left text-xs ${
                        isActive
                          ? 'border-blue-300 bg-blue-50 text-blue-900'
                          : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                      }`}
                    >
                      <span>
                        <span className="block font-medium">{relationship.label}</span>
                        <span className="block font-mono text-[10px] text-gray-500">
                          {relationship.local_column} → {relationship.parent_column ?? 'id'}
                        </span>
                      </span>
                      {isDefault && (
                        <span className="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">
                          Default
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            </div>
          </Panel>
        )}

        <Panel position="top-right">
          <div className="flex flex-col items-end gap-2">
            <button
              type="button"
              aria-label="Re-layout relationship graph"
              onClick={() => {
                explicitRelayoutPending.current = true;
                automaticTopologySignature.current = topologySignature;
                void runAutomaticLayout(graphNodes, graphEdges, true);
              }}
              disabled={layoutPending || graphNodes.length === 0}
              className="rounded-lg border bg-white/95 px-3 py-2 text-xs font-medium text-gray-700 shadow-sm backdrop-blur transition-colors hover:bg-gray-50 disabled:cursor-wait disabled:opacity-60"
            >
              {layoutPending ? 'Arranging…' : 'Re-layout'}
            </button>
            {layoutMode === 'user-arranged' && (
              <span className="rounded bg-white/90 px-2 py-1 text-[10px] text-gray-500 shadow-sm">
                Manual layout preserved
              </span>
            )}
            {layoutError && (
              <span role="status" className="rounded bg-amber-50 px-2 py-1 text-[11px] text-amber-800 shadow-sm">
                {layoutError}
              </span>
            )}
          </div>
        </Panel>

        {/* Legend */}
        <Panel position="bottom-left">
          <div className="bg-white/95 backdrop-blur border rounded-lg px-4 py-2.5 text-[11px] text-gray-600 space-y-1.5 shadow-sm">
            <div className="flex items-center gap-2.5">
              <div className="w-4 h-4 rounded-md bg-[#1e40af] shadow-sm" />
              <span>Selected table</span>
            </div>
            <div className="flex items-center gap-2.5">
              <div className="w-4 h-4 rounded-md border-2 border-dashed border-gray-400 bg-white" />
              <span>Connected — click to add</span>
            </div>
            <div className="flex items-center gap-2.5">
              <div className="w-4 h-0 border-t-2 border-blue-500" style={{ width: 16 }} />
              <span>Foreign key join</span>
            </div>
            <div className="text-[10px] text-gray-400 mt-1 pt-1 border-t">
              Double-click a selected table to remove it
            </div>
          </div>
        </Panel>
      </ReactFlow>

      {/* Empty state */}
      {selectedTables.length === 0 && (
        <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
          <div className="text-center text-gray-400">
            <p className="text-lg mb-1">No tables selected</p>
            <p className="text-sm">Select tables from the Browse tab to see them here</p>
          </div>
        </div>
      )}
    </div>
  );
}

export default function BuilderGraph(props: Props) {
  return (
    <ReactFlowProvider>
      <BuilderGraphCanvas {...props} />
    </ReactFlowProvider>
  );
}
