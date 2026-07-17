# Builder Relationship Graph Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically arrange the Query Builder relationship graph with ELK while preserving all existing node positions after the user manually arranges the graph.

**Architecture:** Isolate asynchronous ELK conversion and layout in a pure adapter, and isolate manual-position reconciliation in a second pure module. `BuilderGraph` remains responsible for constructing table nodes and relationship edges, tracking `automatic` versus `user-arranged` mode, discarding stale layout results, and fitting the React Flow viewport only after accepted automatic layouts.

**Tech Stack:** React 18, TypeScript 5.6, `@xyflow/react` 12, ELK.js, Tailwind CSS, Vitest, Testing Library

## Global Constraints

- Run ELK for initial display and topology changes only while the graph is in `automatic` mode.
- A completed node drag changes the graph to `user-arranged` mode.
- In `user-arranged` mode, adding or removing tables must not move retained visible nodes.
- Clicking an existing ghost table and promoting it to selected must preserve its position.
- `Re-layout` is the only action that may replace a user-arranged layout with a full automatic layout.
- Zooming, panning, selecting, and clicking nodes must not change layout mode.
- Layout changes affect positions only; they must not change selected tables, joins, columns, filters, sorting, or generated SQL.
- Closing and reopening the graph resets the session to `automatic`; persistent layouts are out of scope.
- Keep the current limit of ten visible connected ghost tables.
- Respect `prefers-reduced-motion` when animating position changes.
- Preserve current selected/ghost node colors, labels, click-to-add behavior, and double-click-to-remove behavior.

---

### Task 1: Add The ELK Layout Adapter

**Files:**
- Modify: `frontend/package.json`
- Modify: `frontend/package-lock.json`
- Create: `frontend/src/components/builderGraphLayout.ts`
- Create: `frontend/src/components/builderGraphLayout.test.ts`

**Interfaces:**
- Consumes: React Flow `Node[]` and `Edge[]` representing the complete visible relationship graph.
- Produces: `layoutRelationshipGraph(input: RelationshipLayoutInput): Promise<RelationshipLayoutResult>`.

- [ ] **Step 1: Install the layout dependency**

Run:

```bash
cd frontend
npm install elkjs
```

Expected: `elkjs` is added to `dependencies` and the lockfile changes without unrelated package upgrades.

- [ ] **Step 2: Write the failing adapter tests**

Create `frontend/src/components/builderGraphLayout.test.ts` with fixtures for a parent chain, disconnected nodes, and a cycle. Assert:

```ts
import { describe, expect, it } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import { layoutRelationshipGraph } from './builderGraphLayout';

const node = (id: string): Node => ({
  id,
  position: { x: 0, y: 0 },
  measured: { width: 180, height: 48 },
  data: { label: id },
});

describe('layoutRelationshipGraph', () => {
  it('orders a parent chain from left to right without overlap', async () => {
    const nodes = [node('items'), node('holdings'), node('instances')];
    const edges: Edge[] = [
      { id: 'items-holdings', source: 'items', target: 'holdings' },
      { id: 'holdings-instances', source: 'holdings', target: 'instances' },
    ];

    const result = await layoutRelationshipGraph({ nodes, edges, direction: 'RIGHT' });
    const positions = Object.fromEntries(result.nodes.map((entry) => [entry.id, entry.position]));

    expect(positions.items.x).toBeLessThan(positions.holdings.x);
    expect(positions.holdings.x).toBeLessThan(positions.instances.x);
    expect(new Set(result.nodes.map((entry) => `${entry.position.x}:${entry.position.y}`)).size).toBe(3);
  });

  it('preserves node identity and data', async () => {
    const nodes = [node('items'), node('holdings')];
    const result = await layoutRelationshipGraph({ nodes, edges: [], direction: 'RIGHT' });

    expect(result.nodes.map((entry) => entry.id).sort()).toEqual(['holdings', 'items']);
    expect(result.nodes.find((entry) => entry.id === 'items')?.data).toEqual({ label: 'items' });
  });

  it('separates disconnected nodes', async () => {
    const result = await layoutRelationshipGraph({
      nodes: [node('items'), node('funds')],
      edges: [],
      direction: 'RIGHT',
    });

    expect(result.nodes[0].position).not.toEqual(result.nodes[1].position);
  });

  it('lays out a cycle without throwing', async () => {
    const nodes = [node('a'), node('b')];
    const edges: Edge[] = [
      { id: 'a-b', source: 'a', target: 'b' },
      { id: 'b-a', source: 'b', target: 'a' },
    ];

    await expect(layoutRelationshipGraph({ nodes, edges, direction: 'RIGHT' })).resolves.toMatchObject({
      nodes: expect.arrayContaining([expect.objectContaining({ id: 'a' }), expect.objectContaining({ id: 'b' })]),
    });
  });
});
```

- [ ] **Step 3: Run the adapter tests and verify RED**

Run:

```bash
cd frontend
npm test -- src/components/builderGraphLayout.test.ts
```

Expected: FAIL because `builderGraphLayout.ts` does not exist.

- [ ] **Step 4: Implement the adapter**

Create `frontend/src/components/builderGraphLayout.ts` with this public contract and centralized configuration:

```ts
import ELK from 'elkjs/lib/elk.bundled.js';
import type { Edge, Node } from '@xyflow/react';

const elk = new ELK();
const DEFAULT_NODE_WIDTH = 190;
const DEFAULT_NODE_HEIGHT = 48;

export interface RelationshipLayoutInput {
  nodes: Node[];
  edges: Edge[];
  direction: 'RIGHT';
}

export interface RelationshipLayoutResult {
  nodes: Node[];
  edges: Edge[];
}

export async function layoutRelationshipGraph({
  nodes,
  edges,
  direction,
}: RelationshipLayoutInput): Promise<RelationshipLayoutResult> {
  if (nodes.length === 0) {
    return { nodes, edges };
  }

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
}
```

- [ ] **Step 5: Run the adapter tests and verify GREEN**

Run:

```bash
cd frontend
npm test -- src/components/builderGraphLayout.test.ts
```

Expected: all four adapter tests PASS.

- [ ] **Step 6: Commit the adapter slice**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/components/builderGraphLayout.ts frontend/src/components/builderGraphLayout.test.ts
git commit -m "feat: add builder graph elk layout adapter"
```

---

### Task 2: Preserve User-Arranged Positions

**Files:**
- Create: `frontend/src/components/builderGraphPositions.ts`
- Create: `frontend/src/components/builderGraphPositions.test.ts`

**Interfaces:**
- Consumes: next graph nodes, current positioned nodes, and next graph edges.
- Produces: `reconcileUserArrangedNodes(nextNodes, currentNodes, edges): Node[]`.

- [ ] **Step 1: Write the failing reconciliation tests**

Create `frontend/src/components/builderGraphPositions.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import { reconcileUserArrangedNodes } from './builderGraphPositions';

const node = (id: string, x: number, y: number, isSelected = true): Node => ({
  id,
  position: { x, y },
  data: { tableName: id, isSelected },
});

describe('reconcileUserArrangedNodes', () => {
  it('preserves every retained position', () => {
    const current = [node('items', 25, 40), node('holdings', 410, 80)];
    const next = [node('items', 0, 0), node('holdings', 0, 0)];

    expect(reconcileUserArrangedNodes(next, current, [])).toEqual([
      expect.objectContaining({ id: 'items', position: { x: 25, y: 40 } }),
      expect.objectContaining({ id: 'holdings', position: { x: 410, y: 80 } }),
    ]);
  });

  it('preserves a ghost position when the same table becomes selected', () => {
    const current = [node('items', 25, 40), node('locations', 360, 240, false)];
    const next = [node('items', 0, 0), node('locations', 0, 0, true)];

    expect(reconcileUserArrangedNodes(next, current, [
      { id: 'items-locations', source: 'items', target: 'locations' },
    ])).toContainEqual(expect.objectContaining({ id: 'locations', position: { x: 360, y: 240 } }));
  });

  it('places a new connected node near its retained neighbor without moving the neighbor', () => {
    const current = [node('items', 100, 100)];
    const next = [node('items', 0, 0), node('holdings', 0, 0)];
    const edges: Edge[] = [{ id: 'items-holdings', source: 'items', target: 'holdings' }];
    const result = reconcileUserArrangedNodes(next, current, edges);

    expect(result.find((entry) => entry.id === 'items')?.position).toEqual({ x: 100, y: 100 });
    expect(result.find((entry) => entry.id === 'holdings')?.position.x).toBeGreaterThan(100);
  });

  it('uses a collision-free fallback for multiple new neighbors', () => {
    const current = [node('items', 100, 100)];
    const next = [node('items', 0, 0), node('holdings', 0, 0), node('locations', 0, 0)];
    const edges: Edge[] = [
      { id: 'items-holdings', source: 'items', target: 'holdings' },
      { id: 'items-locations', source: 'items', target: 'locations' },
    ];
    const result = reconcileUserArrangedNodes(next, current, edges);
    const positions = result.map((entry) => `${entry.position.x}:${entry.position.y}`);

    expect(new Set(positions).size).toBe(positions.length);
  });

  it('anchors a new node to its most strongly connected retained neighbor', () => {
    const current = [node('items', 100, 100), node('holdings', 100, 500)];
    const next = [...current, node('locations', 0, 0)];
    const edges: Edge[] = [
      { id: 'locations-items', source: 'locations', target: 'items' },
      { id: 'locations-holdings-1', source: 'locations', target: 'holdings' },
      { id: 'locations-holdings-2', source: 'locations', target: 'holdings' },
    ];

    const result = reconcileUserArrangedNodes(next, current, edges);
    expect(result.find((entry) => entry.id === 'locations')?.position).toEqual({ x: 360, y: 500 });
  });
});
```

- [ ] **Step 2: Run the reconciliation tests and verify RED**

Run:

```bash
cd frontend
npm test -- src/components/builderGraphPositions.test.ts
```

Expected: FAIL because `builderGraphPositions.ts` does not exist.

- [ ] **Step 3: Implement position reconciliation**

Create `frontend/src/components/builderGraphPositions.ts`:

```ts
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
```

- [ ] **Step 4: Run the reconciliation tests and verify GREEN**

Run:

```bash
cd frontend
npm test -- src/components/builderGraphPositions.test.ts
```

Expected: all five reconciliation tests PASS.

- [ ] **Step 5: Commit the reconciliation slice**

```bash
git add frontend/src/components/builderGraphPositions.ts frontend/src/components/builderGraphPositions.test.ts
git commit -m "feat: preserve builder graph node positions"
```

---

### Task 3: Integrate Automatic And User-Arranged Layout Modes

**Files:**
- Modify: `frontend/src/components/BuilderGraph.tsx`
- Modify: `frontend/src/index.css`
- Create: `frontend/src/components/BuilderGraph.test.tsx`

**Interfaces:**
- Consumes: `layoutRelationshipGraph()` from Task 1 and `reconcileUserArrangedNodes()` from Task 2.
- Produces: initial ELK layout, automatic topology re-layout, preserved manual positions, stale-result protection, non-blocking failure display, and the `Re-layout` control.

- [ ] **Step 1: Write the failing interaction tests**

Create `frontend/src/components/BuilderGraph.test.tsx`. Use this harness before the individual test cases so React Flow state, viewport calls, deferred layouts, and graph fixtures are concrete:

```tsx
import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import type { TableDetail, TableSummary } from '../types';
import BuilderGraph from './BuilderGraph';
import { layoutRelationshipGraph } from './builderGraphLayout';
import { reconcileUserArrangedNodes } from './builderGraphPositions';

const flowHarness = vi.hoisted(() => ({
  fitView: vi.fn(),
  latestProps: null as Record<string, unknown> | null,
}));

vi.mock('@xyflow/react', async () => {
  const ReactModule = await import('react');
  return {
    ReactFlowProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    ReactFlow: (props: Record<string, unknown> & { nodes: Node[]; children?: React.ReactNode }) => {
      flowHarness.latestProps = props;
      return (
        <div data-testid="react-flow">
          {props.nodes.map((node) => <div key={node.id} data-testid={`node-${node.id}`} />)}
          {props.children}
        </div>
      );
    },
    Background: () => null,
    Controls: () => null,
    Panel: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    MarkerType: { ArrowClosed: 'arrowclosed' },
    useReactFlow: () => ({ fitView: flowHarness.fitView }),
    useNodesState: (initial: Node[]) => {
      const [nodes, setNodes] = ReactModule.useState(initial);
      return [nodes, setNodes, vi.fn()] as const;
    },
    useEdgesState: (initial: Edge[]) => {
      const [edges, setEdges] = ReactModule.useState(initial);
      return [edges, setEdges, vi.fn()] as const;
    },
  };
});

vi.mock('./builderGraphLayout', () => ({ layoutRelationshipGraph: vi.fn() }));
vi.mock('./builderGraphPositions', () => ({
  reconcileUserArrangedNodes: vi.fn((nextNodes: Node[], currentNodes: Node[]) => {
    const positions = new Map(currentNodes.map((node) => [node.id, node.position]));
    return nextNodes.map((node) => ({ ...node, position: positions.get(node.id) ?? node.position }));
  }),
}));

const tables = {
  items: { name: 'items' },
  holdings: { name: 'holdings' },
} as Record<string, TableSummary>;

const tableDetails = {
  items: {
    relationships: {
      parents: [{ local_column: 'holdings_record_id', parent_table: 'holdings', parent_column: 'id' }],
      children: [],
    },
  },
  holdings: {
    relationships: { parents: [], children: [] },
  },
} as unknown as Record<string, TableDetail>;

const graphElement = (selectedTables: string[]) => (
  <BuilderGraph
    selectedTables={selectedTables}
    tableDetails={tableDetails}
    tables={tables}
    onAddTable={vi.fn()}
    onRemoveTable={vi.fn()}
  />
);

const renderGraph = (selectedTables: string[]) => render(graphElement(selectedTables));

const layoutWithIds = (ids: string[]) => ({
  nodes: ids.map((id, index): Node => ({
    id,
    position: { x: index * 250, y: 0 },
    data: { tableName: id, isSelected: true },
  })),
  edges: [] as Edge[],
});

function deferredLayout() {
  let resolve!: (value: ReturnType<typeof layoutWithIds>) => void;
  const promise = new Promise<ReturnType<typeof layoutWithIds>>((next) => { resolve = next; });
  return { promise, resolve };
}

const renderedNodeIds = () => screen
  .getAllByTestId(/^node-/)
  .map((element) => element.dataset.testid?.replace('node-', ''))
  .filter((id): id is string => Boolean(id))
  .sort();

const draggedItemsNode: Node = {
  id: 'items',
  position: { x: 480, y: 220 },
  data: { tableName: 'items', isSelected: true },
};

beforeEach(() => {
  vi.clearAllMocks();
  flowHarness.latestProps = null;
  vi.mocked(layoutRelationshipGraph).mockImplementation(async ({ nodes, edges }) => ({
    nodes: nodes.map((node, index) => ({ ...node, position: { x: index * 250, y: 0 } })),
    edges,
  }));
});
```

Append this complete `describe` block:

```tsx
describe('BuilderGraph layout behavior', () => {
it('runs automatic layout for the initial graph and fits the viewport', async () => {
  renderGraph(['items']);
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
  expect(flowHarness.fitView).toHaveBeenCalledWith({ padding: 0.4, maxZoom: 1.2, duration: 250 });
});

it('reruns full layout when topology changes before a manual drag', async () => {
  const view = renderGraph(['items']);
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
  view.rerender(graphElement(['items', 'holdings']));
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
});

it('preserves retained positions after a node drag', async () => {
  const view = renderGraph(['items']);
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
  act(() => {
    const onNodeDragStop = flowHarness.latestProps?.onNodeDragStop as (event: unknown, node: Node) => void;
    onNodeDragStop({}, draggedItemsNode);
  });
  view.rerender(graphElement(['items', 'holdings']));
  await waitFor(() => expect(reconcileUserArrangedNodes).toHaveBeenCalled());
  expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
});

it('runs full layout again only after Re-layout is clicked', async () => {
  const user = userEvent.setup();
  renderGraph(['items']);
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
  act(() => {
    const onNodeDragStop = flowHarness.latestProps?.onNodeDragStop as (event: unknown, node: Node) => void;
    onNodeDragStop({}, draggedItemsNode);
  });
  await user.click(screen.getByRole('button', { name: 'Re-layout relationship graph' }));
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
});

it('ignores stale layout results and retains the latest topology', async () => {
  const first = deferredLayout();
  const second = deferredLayout();
  vi.mocked(layoutRelationshipGraph)
    .mockReturnValueOnce(first.promise)
    .mockReturnValueOnce(second.promise);
  const view = renderGraph(['items']);
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
  view.rerender(graphElement(['items', 'holdings']));
  await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
  second.resolve(layoutWithIds(['items', 'holdings']));
  first.resolve(layoutWithIds(['items']));
  await waitFor(() => expect(renderedNodeIds()).toEqual(['holdings', 'items']));
});

it('keeps current positions and shows a non-blocking message when layout fails', async () => {
  vi.mocked(layoutRelationshipGraph).mockRejectedValueOnce(new Error('layout failed'));
  renderGraph(['items']);
  expect(await screen.findByText('Could not arrange this graph')).toBeInTheDocument();
  expect(screen.getByTestId('node-items')).toBeInTheDocument();
});
});
```

- [ ] **Step 2: Run the interaction tests and verify RED**

Run:

```bash
cd frontend
npm test -- src/components/BuilderGraph.test.tsx
```

Expected: FAIL because `BuilderGraph` does not call ELK, track manual layout mode, or render a Re-layout control.

- [ ] **Step 3: Remove the fixed grid layout**

Delete `computeLayout()`. Build selected and ghost nodes with a neutral initial position such as `{ x: 60, y: 60 }`; keep their existing IDs, data, styles, click behavior, double-click behavior, and maximum ghost count. Set all relationship edges to `type: 'smoothstep'` while preserving their existing labels, arrows, colors, and dashed/solid styles.

- [ ] **Step 4: Add React Flow provider and layout state**

Wrap the canvas so viewport operations are available inside the component:

```tsx
export default function BuilderGraph(props: Props) {
  return (
    <ReactFlowProvider>
      <BuilderGraphCanvas {...props} />
    </ReactFlowProvider>
  );
}
```

Inside `BuilderGraphCanvas`, add:

```tsx
type LayoutMode = 'automatic' | 'user-arranged';

const [layoutMode, setLayoutMode] = useState<LayoutMode>('automatic');
const layoutModeRef = useRef<LayoutMode>('automatic');
const [layoutPending, setLayoutPending] = useState(false);
const [layoutError, setLayoutError] = useState<string | null>(null);
const layoutSequence = useRef(0);
const { fitView } = useReactFlow();
```

Retain `useNodesState` and `useEdgesState`, but do not overwrite positioned nodes directly from raw graph data.

- [ ] **Step 5: Implement accepted automatic layouts**

Add one callback used by initial layout, automatic topology changes, and the button:

```tsx
const runAutomaticLayout = useCallback(async (
  nextNodes: Node[],
  nextEdges: Edge[],
  resetMode: boolean,
) => {
  const sequence = ++layoutSequence.current;
  setLayoutPending(true);
  setLayoutError(null);
  try {
    const result = await layoutRelationshipGraph({
      nodes: nextNodes,
      edges: nextEdges,
      direction: 'RIGHT',
    });
    if (sequence !== layoutSequence.current) return;
    setNodes(result.nodes);
    setEdges(result.edges);
    if (resetMode) {
      layoutModeRef.current = 'automatic';
      setLayoutMode('automatic');
    }
    requestAnimationFrame(() => {
      void fitView({ padding: 0.4, maxZoom: 1.2, duration: 250 });
    });
  } catch {
    if (sequence !== layoutSequence.current) return;
    setLayoutError('Could not arrange this graph');
    setNodes((current) => reconcileUserArrangedNodes(nextNodes, current, nextEdges));
    setEdges(nextEdges);
  } finally {
    if (sequence === layoutSequence.current) setLayoutPending(false);
  }
}, [fitView, setEdges, setNodes]);
```

Use a topology effect with the raw graph nodes and edges:

```tsx
useEffect(() => {
  if (layoutModeRef.current === 'automatic') {
    void runAutomaticLayout(graphNodes, graphEdges, false);
    return;
  }
  layoutSequence.current += 1;
  setNodes((current) => reconcileUserArrangedNodes(graphNodes, current, graphEdges));
  setEdges(graphEdges);
}, [graphEdges, graphNodes, runAutomaticLayout, setEdges, setNodes]);
```

Use stable memoized graph inputs so position state changes do not retrigger topology layout. Do not add `layoutMode` to this effect's dependencies: the ref is the authoritative value for topology decisions, and changing the display state after a successful button layout must not start a duplicate ELK request.

- [ ] **Step 6: Preserve explicit user intent and add Re-layout**

Add drag completion without treating viewport changes as layout changes:

```tsx
const onNodeDragStop = useCallback(() => {
  layoutSequence.current += 1;
  layoutModeRef.current = 'user-arranged';
  setLayoutMode('user-arranged');
  setLayoutPending(false);
  setLayoutError(null);
}, []);
```

Pass `onNodeDragStop` to `ReactFlow`. Add a top-right panel:

```tsx
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
```

- [ ] **Step 7: Add restrained, reduced-motion-safe movement**

Add a graph-specific class to the wrapper and update `frontend/src/index.css`:

```css
.builder-relationship-graph .react-flow__node {
  transition: transform 250ms ease;
}

.builder-relationship-graph .react-flow__node.dragging {
  transition: none;
}

@media (prefers-reduced-motion: reduce) {
  .builder-relationship-graph .react-flow__node {
    transition: none;
  }
}
```

Do not add page-wide motion or change the established Builder visual system.

- [ ] **Step 8: Run interaction and helper tests**

Run:

```bash
cd frontend
npm test -- src/components/BuilderGraph.test.tsx src/components/builderGraphLayout.test.ts src/components/builderGraphPositions.test.ts
```

Expected: all Builder graph tests PASS.

- [ ] **Step 9: Commit the graph integration**

```bash
git add frontend/src/components/BuilderGraph.tsx frontend/src/components/BuilderGraph.test.tsx frontend/src/index.css
git commit -m "feat: add opt-in builder graph relayout"
```

---

### Task 4: Verify Query Builder Compatibility

**Files:**
- Verify only; no additional files expected.

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: evidence that the relationship graph is readable and preserves user intent without changing Query Builder semantics.

- [ ] **Step 1: Run focused graph tests**

Run:

```bash
cd frontend
npm test -- src/components/BuilderGraph.test.tsx src/components/builderGraphLayout.test.ts src/components/builderGraphPositions.test.ts
```

Expected: all focused tests PASS.

- [ ] **Step 2: Run the complete frontend test suite**

Run:

```bash
cd frontend
npm test
```

Expected: all Vitest suites PASS.

- [ ] **Step 3: Run type and production-build validation**

Run:

```bash
cd frontend
npm run build
```

Expected: TypeScript compilation and the Vite production build complete successfully.

- [ ] **Step 4: Run lint validation**

Run:

```bash
cd frontend
npm run lint
```

Expected: ESLint reports no new errors in the Builder graph files.

- [ ] **Step 5: Perform the manual interaction check**

Open Query Builder and verify this sequence:

1. Select one Inventory table and open Relationship Graph.
2. Confirm the initial selected and connected nodes are arranged automatically.
3. Add a connected table without dragging and confirm the complete graph rearranges and refits.
4. Drag two selected tables into a recognizable manual layout.
5. Add another connected table and confirm both dragged tables remain exactly where they were.
6. Remove a table and confirm retained nodes do not move.
7. Click `Re-layout` and confirm the complete graph rearranges, animates, and fits into view.
8. Drag again and confirm manual preservation begins again.
9. Close and reopen the modal and confirm a fresh automatic layout is calculated.

Expected: all nine checks match the approved design and table add/remove behavior still updates the Builder selection.

- [ ] **Step 6: Inspect the final diff**

Run:

```bash
git diff --check HEAD~3..HEAD
git diff --stat HEAD~3..HEAD
```

Expected: no whitespace errors; changes are limited to ELK dependency metadata, the layout/reconciliation helpers and tests, `BuilderGraph`, and graph-specific CSS.

- [ ] **Step 7: Record verification completion**

```bash
git commit --allow-empty -m "test: verify builder relationship graph layout"
```

The commit message records the manual and automated verification checkpoint without introducing additional source changes.
