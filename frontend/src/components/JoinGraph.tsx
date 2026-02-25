import { useMemo, useCallback } from 'react';
import {
  ReactFlow,
  Background,
  Controls,
  type Node,
  type Edge,
  MarkerType,
  useNodesState,
  useEdgesState,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import type { Relationship } from '../types';

interface Props {
  tableName: string;
  relationships: {
    parents: Relationship[];
    children: Relationship[];
  };
  onNavigateTable?: (name: string) => void;
}

export default function JoinGraph({ tableName, relationships, onNavigateTable }: Props) {
  const { initialNodes, initialEdges } = useMemo(() => {
    const nodes: Node[] = [];
    const edges: Edge[] = [];
    const neighbors = new Set<string>();

    // Center node
    nodes.push({
      id: tableName,
      data: { label: tableName },
      position: { x: 300, y: 200 },
      style: {
        background: '#1e40af',
        color: 'white',
        border: '2px solid #1e3a8a',
        borderRadius: '8px',
        padding: '10px 16px',
        fontFamily: 'monospace',
        fontSize: '12px',
        fontWeight: 'bold',
      },
    });

    // Parent nodes (above)
    relationships.parents.forEach((p, i) => {
      const target = p.parent_table || '';
      if (!target || neighbors.has(target)) return;
      neighbors.add(target);

      const angle = (Math.PI / (relationships.parents.length + 1)) * (i + 1);
      nodes.push({
        id: target,
        data: { label: target },
        position: {
          x: 300 + Math.cos(angle) * 250 - 60,
          y: 200 - Math.sin(angle) * 150 - 40,
        },
        style: {
          background: '#eff6ff',
          border: '1px solid #93c5fd',
          borderRadius: '8px',
          padding: '8px 12px',
          fontFamily: 'monospace',
          fontSize: '11px',
          cursor: 'pointer',
        },
      });

      edges.push({
        id: `${tableName}->${target}`,
        source: tableName,
        target: target,
        label: `${p.local_column}`,
        style: { strokeWidth: 2 },
        markerEnd: { type: MarkerType.ArrowClosed },
        labelStyle: { fontSize: 10, fill: '#6b7280' },
      });
    });

    // Child nodes (below)
    relationships.children.forEach((c, i) => {
      const target = c.child_table || '';
      if (!target || neighbors.has(target)) return;
      neighbors.add(target);

      const angle = (Math.PI / (relationships.children.length + 1)) * (i + 1);
      nodes.push({
        id: target,
        data: { label: target },
        position: {
          x: 300 + Math.cos(angle) * 250 - 60,
          y: 200 + Math.sin(angle) * 150 + 40,
        },
        style: {
          background: '#fef3c7',
          border: '1px solid #fbbf24',
          borderRadius: '8px',
          padding: '8px 12px',
          fontFamily: 'monospace',
          fontSize: '11px',
          cursor: 'pointer',
        },
      });

      edges.push({
        id: `${target}->${tableName}`,
        source: target,
        target: tableName,
        label: `${c.local_column}`,
        style: { strokeWidth: 2 },
        markerEnd: { type: MarkerType.ArrowClosed },
        labelStyle: { fontSize: 10, fill: '#6b7280' },
      });
    });

    return { initialNodes: nodes, initialEdges: edges };
  }, [tableName, relationships]);

  const [nodes, , onNodesChange] = useNodesState(initialNodes);
  const [edges, , onEdgesChange] = useEdgesState(initialEdges);

  const onNodeClick = useCallback(
    (_: React.MouseEvent, node: Node) => {
      if (node.id !== tableName && onNavigateTable) {
        onNavigateTable(node.id);
      }
    },
    [tableName, onNavigateTable],
  );

  if (initialNodes.length <= 1) {
    return (
      <div className="flex items-center justify-center h-full text-sm text-gray-400">
        No FK relationships to display
      </div>
    );
  }

  return (
    <div className="h-full w-full">
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onNodeClick={onNodeClick}
        fitView
        proOptions={{ hideAttribution: true }}
      >
        <Background />
        <Controls />
      </ReactFlow>
    </div>
  );
}
