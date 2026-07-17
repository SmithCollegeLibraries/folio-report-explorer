import type { Edge, Node } from '@xyflow/react';
import type { ELK } from 'elkjs/lib/elk-api';

const DEFAULT_NODE_WIDTH = 190;
const DEFAULT_NODE_HEIGHT = 48;

export type ElkLayoutEngine = Pick<ELK, 'layout'>;
export type ElkLayoutLoader = () => Promise<ElkLayoutEngine>;

export interface RelationshipLayoutInput {
  nodes: Node[];
  edges: Edge[];
  direction: 'RIGHT';
}

export interface RelationshipLayoutResult {
  nodes: Node[];
  edges: Edge[];
}

export type RelationshipGraphLayout = (
  input: RelationshipLayoutInput,
) => Promise<RelationshipLayoutResult>;

const loadBundledElk: ElkLayoutLoader = async (): Promise<ElkLayoutEngine> => {
  const { default: ELK } = await import('elkjs/lib/elk.bundled.js');
  return new ELK();
};

export function createRelationshipGraphLayout(
  loadElk: ElkLayoutLoader = loadBundledElk,
): RelationshipGraphLayout {
  let elkPromise: Promise<ElkLayoutEngine> | null = null;

  return async ({
    nodes,
    edges,
    direction,
  }: RelationshipLayoutInput): Promise<RelationshipLayoutResult> => {
    if (nodes.length === 0) {
      return { nodes, edges };
    }

    elkPromise ??= loadElk();
    const elk = await elkPromise;

    const graph = await elk.layout({
      id: 'builder-relationship-graph',
      layoutOptions: {
        'elk.algorithm': 'layered',
        'elk.direction': direction,
        'elk.edgeRouting': 'ORTHOGONAL',
        'elk.spacing.nodeNode': '80',
        'elk.layered.spacing.nodeNodeBetweenLayers': '140',
        'elk.layered.crossingMinimization.strategy': 'LAYER_SWEEP',
        'elk.separateConnectedComponents': 'true',
        'elk.spacing.componentComponent': '120',
      },
      children: nodes.map((node) => ({
        id: node.id,
        width: node.measured?.width ?? DEFAULT_NODE_WIDTH,
        height: node.measured?.height ?? DEFAULT_NODE_HEIGHT,
      })),
      edges: edges.map((edge) => ({
        id: edge.id,
        sources: [edge.source],
        targets: [edge.target],
      })),
    });

    const positions = new Map(
      (graph.children ?? []).map((child) => [
        child.id,
        { x: child.x ?? 0, y: child.y ?? 0 },
      ]),
    );

    return {
      nodes: nodes.map((node) => ({
        ...node,
        position: positions.get(node.id) ?? node.position,
      })),
      edges: edges.map((edge) => ({ ...edge, type: 'smoothstep' })),
    };
  };
}

export const layoutRelationshipGraph = createRelationshipGraphLayout();
