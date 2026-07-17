import type { Edge, Node, XYPosition } from '@xyflow/react';

const HORIZONTAL_OFFSET = 260;
const VERTICAL_OFFSET = 140;
const COLLISION_WIDTH = 220;
const COLLISION_HEIGHT = 90;

function overlaps(position: XYPosition, occupied: XYPosition[]): boolean {
  return occupied.some((other) => (
    Math.abs(position.x - other.x) < COLLISION_WIDTH
    && Math.abs(position.y - other.y) < COLLISION_HEIGHT
  ));
}

function connectedRetainedPosition(
  nodeId: string,
  edges: Edge[],
  retained: Map<string, XYPosition>,
): XYPosition | null {
  const connectionCounts = new Map<string, number>();
  for (const edge of edges) {
    const neighborId = edge.source === nodeId
      ? edge.target
      : edge.target === nodeId
        ? edge.source
        : null;
    if (neighborId && retained.has(neighborId)) {
      connectionCounts.set(neighborId, (connectionCounts.get(neighborId) ?? 0) + 1);
    }
  }
  const strongestNeighbor = [...connectionCounts.entries()]
    .sort(([leftId, leftCount], [rightId, rightCount]) => (
      rightCount - leftCount || leftId.localeCompare(rightId)
    ))[0]?.[0];
  return strongestNeighbor ? retained.get(strongestNeighbor) ?? null : null;
}

function firstOpenPosition(anchor: XYPosition, occupied: XYPosition[]): XYPosition {
  const candidates: XYPosition[] = [
    { x: anchor.x + HORIZONTAL_OFFSET, y: anchor.y },
    { x: anchor.x + HORIZONTAL_OFFSET, y: anchor.y + VERTICAL_OFFSET },
    { x: anchor.x + HORIZONTAL_OFFSET, y: anchor.y - VERTICAL_OFFSET },
    { x: anchor.x, y: anchor.y + VERTICAL_OFFSET * 2 },
    { x: anchor.x, y: anchor.y - VERTICAL_OFFSET * 2 },
  ];

  for (const candidate of candidates) {
    if (!overlaps(candidate, occupied)) return candidate;
  }

  let row = 3;
  while (row < 100) {
    const candidate = {
      x: anchor.x + HORIZONTAL_OFFSET,
      y: anchor.y + row * VERTICAL_OFFSET,
    };
    if (!overlaps(candidate, occupied)) return candidate;
    row += 1;
  }

  return { x: anchor.x + HORIZONTAL_OFFSET, y: anchor.y + row * VERTICAL_OFFSET };
}

export function reconcileUserArrangedNodes(
  nextNodes: Node[],
  currentNodes: Node[],
  edges: Edge[],
): Node[] {
  const currentPositions = new Map(currentNodes.map((node) => [node.id, node.position]));
  const retained = new Map<string, XYPosition>();

  for (const node of nextNodes) {
    const position = currentPositions.get(node.id);
    if (position) retained.set(node.id, position);
  }

  const occupied = [...retained.values()];
  const defaultAnchor = occupied[0] ?? { x: 60, y: 60 };

  return nextNodes.map((node) => {
    const retainedPosition = retained.get(node.id);
    if (retainedPosition) {
      return { ...node, position: retainedPosition };
    }

    const anchor = connectedRetainedPosition(node.id, edges, retained) ?? defaultAnchor;
    const position = firstOpenPosition(anchor, occupied);
    occupied.push(position);
    retained.set(node.id, position);
    return { ...node, position };
  });
}
