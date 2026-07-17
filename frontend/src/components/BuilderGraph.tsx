import { useMemo, useCallback, useEffect, useRef, useState } from 'react';
import {
  ReactFlow,
  ReactFlowProvider,
  Background,
  Controls,
  type Node,
  type Edge,
  MarkerType,
  useNodesState,
  useEdgesState,
  useReactFlow,
  Panel,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import type { TableDetail, TableSummary } from '../types';
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
}

function shortName(fullName: string): string {
  const dotIdx = fullName.indexOf('.');
  return dotIdx >= 0 ? fullName.substring(dotIdx + 1) : fullName;
}

type LayoutMode = 'automatic' | 'user-arranged';

function BuilderGraphCanvas({
  selectedTables,
  tableDetails,
  tables,
  onAddTable,
  onRemoveTable,
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
    for (const t of selectedTables) {
      const detail = tableDetails[t];
      if (!detail?.relationships) continue;

      for (const rel of detail.relationships.parents || []) {
        if (!rel.parent_table || !selectedSet.has(rel.parent_table)) continue;
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
  }, [selectedTables, tableDetails, connectedTables]);

  const [nodes, setNodes, onNodesChange] = useNodesState(graphNodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(graphEdges);
  const [layoutMode, setLayoutMode] = useState<LayoutMode>('automatic');
  const layoutModeRef = useRef<LayoutMode>('automatic');
  const [layoutPending, setLayoutPending] = useState(false);
  const [layoutError, setLayoutError] = useState<string | null>(null);
  const layoutSequence = useRef(0);
  const layoutFrame = useRef<number | null>(null);
  const mounted = useRef(true);
  const { fitView } = useReactFlow();

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
        const promotedPositions = new Map(
          nextNodes
            .filter((node) => (
              node.data.isSelected === true
              && currentById.get(node.id)?.data.isSelected === false
            ))
            .map((node) => [node.id, currentById.get(node.id)!.position]),
        );
        return result.nodes.map((node) => ({
          ...node,
          position: promotedPositions.get(node.id) ?? node.position,
        }));
      });
      setEdges(result.edges);
      if (resetMode) {
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
      setNodes((current) => reconcileUserArrangedNodes(nextNodes, current, nextEdges));
      setEdges(nextEdges);
    } finally {
      if (mounted.current && sequence === layoutSequence.current) setLayoutPending(false);
    }
  }, [cancelScheduledFit, fitView, setEdges, setNodes]);

  useEffect(() => {
    if (layoutModeRef.current === 'automatic') {
      void runAutomaticLayout(graphNodes, graphEdges, false);
      return;
    }
    layoutSequence.current += 1;
    cancelScheduledFit();
    setLayoutPending(false);
    setNodes((current) => reconcileUserArrangedNodes(graphNodes, current, graphEdges));
    setEdges(graphEdges);
  }, [cancelScheduledFit, graphEdges, graphNodes, runAutomaticLayout, setEdges, setNodes]);

  const onNodeDragStop = useCallback(() => {
    layoutSequence.current += 1;
    cancelScheduledFit();
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

  return (
    <div className="builder-relationship-graph h-full w-full relative">
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onNodeClick={onNodeClick}
        onNodeDoubleClick={onNodeDoubleClick}
        onNodeDragStop={onNodeDragStop}
        proOptions={{ hideAttribution: true }}
        minZoom={0.2}
        maxZoom={2.5}
        defaultEdgeOptions={{ animated: false }}
      >
        <Background gap={24} size={1} color="#e5e7eb" />
        <Controls showInteractive={false} />

        <Panel position="top-right">
          <div className="flex flex-col items-end gap-2">
            <button
              type="button"
              aria-label="Re-layout relationship graph"
              onClick={() => void runAutomaticLayout(graphNodes, graphEdges, true)}
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
