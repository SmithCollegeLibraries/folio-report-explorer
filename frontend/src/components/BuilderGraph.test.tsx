import React from 'react';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import type { CanonicalRelationship, TableDetail, TableSummary } from '../types';
import BuilderGraph from './BuilderGraph';
import { layoutRelationshipGraph } from './builderGraphLayout';
import { reconcileUserArrangedNodes } from './builderGraphPositions';
import type { RelationshipGroups, RelationshipOverrides } from './builderRelationships';

const flowHarness = vi.hoisted(() => ({
  fitView: vi.fn(),
  latestProps: null as Record<string, unknown> | null,
  animationFrames: [] as { id: number; callback: FrameRequestCallback }[],
  nextFrameId: 1,
  reducedMotion: false,
}));

vi.mock('@xyflow/react', async () => {
  const ReactModule = await import('react');
  return {
    ReactFlowProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    ReactFlow: (props: Record<string, unknown> & {
      nodes: Node[];
      edges: Edge[];
      edgeTypes?: Record<string, React.ComponentType<Record<string, unknown>>>;
      children?: React.ReactNode;
    }) => {
      flowHarness.latestProps = props;
      return (
        <div data-testid="react-flow">
          {props.nodes.map((node) => <div key={node.id} data-testid={`node-${node.id}`} />)}
          {props.edges.map((edge) => {
            const EdgeComponent = edge.type ? props.edgeTypes?.[edge.type] : undefined;
            return EdgeComponent ? (
              <EdgeComponent
                key={edge.id}
                {...edge}
                sourceX={0}
                sourceY={0}
                targetX={100}
                targetY={100}
                sourcePosition="right"
                targetPosition="left"
              />
            ) : null;
          })}
          {props.children}
        </div>
      );
    },
    Background: () => null,
    Controls: () => null,
    Panel: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    BaseEdge: ({ id }: { id: string }) => <span data-testid={`edge-path-${id}`} />,
    EdgeLabelRenderer: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    getSmoothStepPath: () => ['M 0 0 L 100 100', 50, 50],
    MarkerType: { ArrowClosed: 'arrowclosed' },
    useReactFlow: () => ({ fitView: flowHarness.fitView }),
    useNodesState: (initial: Node[]) => {
      const [nodes, setNodes] = ReactModule.useState(initial);
      const onNodesChange = (changes: { id: string; type: string; position?: Node['position'] }[]) => {
        setNodes((current) => current.map((node) => {
          const positionChange = changes.find((change) => (
            change.id === node.id && change.type === 'position' && change.position
          ));
          return positionChange?.position
            ? { ...node, position: positionChange.position }
            : node;
        }));
      };
      return [nodes, setNodes, onNodesChange] as const;
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
} as unknown as Record<string, TableSummary>;

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

const graphElementWithData = (
  selectedTables: string[],
  nextTableDetails: Record<string, TableDetail>,
  nextTables: Record<string, TableSummary>,
) => (
  <BuilderGraph
    selectedTables={selectedTables}
    tableDetails={nextTableDetails}
    tables={nextTables}
    onAddTable={vi.fn()}
    onRemoveTable={vi.fn()}
  />
);

const pairId = 'inventory.item__t<->inventory.location__t';
const effectiveId = 'inventory.item__t.effective_location_id->inventory.location__t.id';
const permanentId = 'inventory.item__t.permanent_location_id->inventory.location__t.id';
const temporaryId = 'inventory.item__t.temporary_location_id->inventory.location__t.id';
const holdingsPairId = 'inventory.holdings_record__t<->inventory.location__t';
const holdingsPermanentId = 'inventory.holdings_record__t.permanent_location_id->inventory.location__t.id';
const holdingsTemporaryId = 'inventory.holdings_record__t.temporary_location_id->inventory.location__t.id';

function canonicalRelationship(
  nextPairId: string,
  relationshipId: string,
  fromTable: string,
  fromColumn: string,
  isDefault = false,
): CanonicalRelationship {
  return {
    from_table: fromTable,
    from_column: fromColumn,
    to_table: 'inventory.location__t',
    to_column: 'id',
    parent_table: 'inventory.location__t',
    parent_column: 'id',
    local_column: fromColumn,
    foreign_key: `${fromTable}_${fromColumn}_fk`,
    relationship_id: relationshipId,
    pair_id: nextPairId,
    label: fromColumn
      .split('_').join(' ')
      .replace(/\bid\b/, '')
      .trim()
      .replace(/^./, (letter: string) => letter.toUpperCase()),
    is_default: isDefault,
    source: 'overlay',
  } as CanonicalRelationship;
}

const effective = canonicalRelationship(
  pairId,
  effectiveId,
  'inventory.item__t',
  'effective_location_id',
  true,
);
const permanent = canonicalRelationship(
  pairId,
  permanentId,
  'inventory.item__t',
  'permanent_location_id',
);
const temporary = canonicalRelationship(
  pairId,
  temporaryId,
  'inventory.item__t',
  'temporary_location_id',
);
const holdingsPermanent = canonicalRelationship(
  holdingsPairId,
  holdingsPermanentId,
  'inventory.holdings_record__t',
  'permanent_location_id',
  true,
);
const holdingsTemporary = canonicalRelationship(
  holdingsPairId,
  holdingsTemporaryId,
  'inventory.holdings_record__t',
  'temporary_location_id',
);

const canonicalTables = {
  'inventory.item__t': { name: 'inventory.item__t' },
  'inventory.location__t': { name: 'inventory.location__t' },
  'inventory.holdings_record__t': { name: 'inventory.holdings_record__t' },
} as unknown as Record<string, TableSummary>;

const canonicalDetails = {
  'inventory.item__t': {
    relationships: { parents: [effective, permanent, temporary], children: [] },
  },
  'inventory.location__t': {
    relationships: {
      parents: [],
      children: [effective, permanent, temporary, holdingsPermanent, holdingsTemporary],
    },
  },
  'inventory.holdings_record__t': {
    relationships: { parents: [holdingsPermanent, holdingsTemporary], children: [] },
  },
} as unknown as Record<string, TableDetail>;

const canonicalGroups: RelationshipGroups = {
  [pairId]: {
    pairId,
    leftTable: 'inventory.item__t',
    rightTable: 'inventory.location__t',
    defaultRelationshipId: effectiveId,
    relationships: [effective, permanent, temporary],
  },
  [holdingsPairId]: {
    pairId: holdingsPairId,
    leftTable: 'inventory.holdings_record__t',
    rightTable: 'inventory.location__t',
    defaultRelationshipId: holdingsPermanentId,
    relationships: [holdingsPermanent, holdingsTemporary],
  },
};

function canonicalGraph(
  overrides: RelationshipOverrides,
  onRelationshipChange: (nextPairId: string, relationshipId: string) => void,
  relationshipGroups: RelationshipGroups = canonicalGroups,
  selectedTables = ['inventory.item__t', 'inventory.location__t'],
) {
  return (
    <BuilderGraph
      selectedTables={selectedTables}
      tableDetails={canonicalDetails}
      tables={canonicalTables}
      onAddTable={vi.fn()}
      onRemoveTable={vi.fn()}
      relationshipGroups={relationshipGroups}
      activeRelationshipOverrides={overrides}
      onRelationshipChange={onRelationshipChange}
    />
  );
}

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

const currentNodes = () => flowHarness.latestProps?.nodes as Node[];

const currentNode = (id: string) => currentNodes().find((node) => node.id === id);

const flushAnimationFrames = () => {
  const frames = flowHarness.animationFrames.splice(0);
  act(() => frames.forEach(({ callback }) => callback(0)));
};

const draggedItemsNode: Node = {
  id: 'items',
  position: { x: 480, y: 220 },
  data: { tableName: 'items', isSelected: true },
};

function dragNode(node: Node) {
  act(() => {
    const onNodesChange = flowHarness.latestProps?.onNodesChange as (changes: unknown[]) => void;
    onNodesChange([{ id: node.id, type: 'position', position: node.position, dragging: false }]);
    const onNodeDragStop = flowHarness.latestProps?.onNodeDragStop as (event: unknown, node: Node) => void;
    onNodeDragStop({}, node);
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    const id = flowHarness.nextFrameId++;
    flowHarness.animationFrames.push({ id, callback });
    return id;
  });
  vi.stubGlobal('cancelAnimationFrame', (id: number) => {
    flowHarness.animationFrames = flowHarness.animationFrames.filter((frame) => frame.id !== id);
  });
  vi.stubGlobal('matchMedia', vi.fn((query: string) => ({
    matches: query === '(prefers-reduced-motion: reduce)' && flowHarness.reducedMotion,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })));
  flowHarness.animationFrames = [];
  flowHarness.nextFrameId = 1;
  flowHarness.reducedMotion = false;
  flowHarness.latestProps = null;
  vi.mocked(layoutRelationshipGraph).mockImplementation(async ({ nodes, edges }) => ({
    nodes: nodes.map((node, index) => ({ ...node, position: { x: index * 250, y: 0 } })),
    edges,
  }));
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('BuilderGraph layout behavior', () => {
  it('selects a relationship from a pair-stable edge without moving nodes or the viewport', async () => {
    const user = userEvent.setup();
    const onRelationshipChange = vi.fn();
    const view = render(canonicalGraph({}, onRelationshipChange));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    flushAnimationFrames();
    const positionBeforeSelection = currentNode('inventory.item__t')?.position;
    const edgeBeforeSelection = (flowHarness.latestProps?.edges as Edge[])
      .find((edge) => edge.id === pairId);
    vi.mocked(reconcileUserArrangedNodes).mockClear();

    const trigger = screen.getByRole('button', {
      name: 'Choose relationship between inventory.item__t and inventory.location__t',
    });
    trigger.focus();
    await user.click(trigger);
    expect(screen.getByRole('dialog', { name: 'Choose relationship' })).toBeInTheDocument();
    expect(screen.getByText('Default')).toBeInTheDocument();
    const effectiveChoice = screen.getByRole('button', { name: /Effective location/ });
    expect(effectiveChoice).toHaveAttribute('aria-pressed', 'true');
    expect(effectiveChoice).toHaveFocus();
    await user.click(screen.getByRole('button', { name: /^Permanent location/ }));

    expect(onRelationshipChange).toHaveBeenCalledWith(pairId, permanentId);
    expect(trigger).toHaveFocus();
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
    expect(flowHarness.fitView).toHaveBeenCalledTimes(1);
    expect(currentNode('inventory.item__t')?.position).toEqual(positionBeforeSelection);

    view.rerender(canonicalGraph({ [pairId]: permanentId }, onRelationshipChange));
    await waitFor(() => expect(screen.getByText(/permanent_location_id → id/)).toBeInTheDocument());
    const edgeAfterSelection = (flowHarness.latestProps?.edges as Edge[])
      .find((edge) => edge.id === pairId);
    expect(edgeAfterSelection).toMatchObject({
      id: edgeBeforeSelection?.id,
      source: edgeBeforeSelection?.source,
      target: edgeBeforeSelection?.target,
    });
    expect(edgeAfterSelection?.data).toMatchObject({ relationshipId: permanentId });
    expect(reconcileUserArrangedNodes).not.toHaveBeenCalled();
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
    expect(flowHarness.fitView).toHaveBeenCalledTimes(1);
    expect(currentNode('inventory.item__t')?.position).toEqual(positionBeforeSelection);
  });

  it('opens the correct pair from the edge path hit target without changing layout', async () => {
    const user = userEvent.setup();
    const onRelationshipChange = vi.fn();
    render(canonicalGraph(
      {},
      onRelationshipChange,
      canonicalGroups,
      ['inventory.item__t', 'inventory.location__t', 'inventory.holdings_record__t'],
    ));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    const positionsBeforeClick = currentNodes().map(({ id, position }) => ({ id, position }));
    const edges = flowHarness.latestProps?.edges as Edge[];
    expect(edges.filter((edge) => edge.type === 'builderRelationship')).toHaveLength(2);
    expect(edges.find((edge) => edge.id === holdingsPairId)?.interactionWidth).toBe(24);

    act(() => {
      const onEdgeClick = flowHarness.latestProps?.onEdgeClick as (event: unknown, edge: Edge) => void;
      onEdgeClick({}, edges.find((edge) => edge.id === holdingsPairId)!);
    });
    const dialog = screen.getByRole('dialog', { name: 'Choose relationship' });
    expect(dialog).toHaveTextContent('inventory.holdings_record__t');
    expect(screen.getByRole('button', { name: /^Permanent location/ })).toHaveFocus();
    await user.click(screen.getByRole('button', { name: 'Close relationship selector' }));

    expect(onRelationshipChange).not.toHaveBeenCalled();
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
    expect(flowHarness.fitView).not.toHaveBeenCalled();
    expect(currentNodes().map(({ id, position }) => ({ id, position }))).toEqual(positionsBeforeClick);
  });

  it('dismisses the selector with Escape or outside interaction and restores trigger focus', async () => {
    const user = userEvent.setup();
    render(canonicalGraph({}, vi.fn()));
    const trigger = await screen.findByRole('button', {
      name: 'Choose relationship between inventory.item__t and inventory.location__t',
    });
    await user.click(trigger);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'Choose relationship' })).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();

    await user.click(trigger);
    await user.click(screen.getByTestId('react-flow'));
    expect(screen.queryByRole('dialog', { name: 'Choose relationship' })).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it('closes a stale selector when its pair or alternatives disappear without relayout', async () => {
    const user = userEvent.setup();
    const view = render(canonicalGraph({}, vi.fn()));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole('button', {
      name: 'Choose relationship between inventory.item__t and inventory.location__t',
    }));
    expect(screen.getByRole('dialog', { name: 'Choose relationship' })).toBeInTheDocument();

    view.rerender(canonicalGraph({}, vi.fn(), {
      [pairId]: { ...canonicalGroups[pairId], relationships: [effective] },
    }));
    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: 'Choose relationship' })).not.toBeInTheDocument();
    });
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
  });

  it('recovers automatic layout state after Strict Mode effect replay', async () => {
    render(<React.StrictMode>{graphElement(['items'])}</React.StrictMode>);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(currentNode('items')?.position).toEqual({ x: 0, y: 0 }));

    expect(screen.getByRole('button', { name: 'Re-layout relationship graph' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Re-layout relationship graph' })).toHaveTextContent('Re-layout');
  });

  it('runs automatic layout for the initial graph and fits the viewport', async () => {
    renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    flushAnimationFrames();
    expect(flowHarness.fitView).toHaveBeenCalledWith({ padding: 0.4, maxZoom: 1.2, duration: 250 });
  });

  it('reruns full layout when topology changes before a manual drag', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
  });

  it('does not lay out or refit an equivalent cloned topology', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    flushAnimationFrames();
    expect(flowHarness.fitView).toHaveBeenCalledTimes(1);
    const positionsBeforeRefresh = currentNodes().map(({ id, position }) => ({ id, position }));

    view.rerender(graphElementWithData(
      structuredClone(['items']),
      structuredClone(tableDetails),
      structuredClone(tables),
    ));
    await act(async () => Promise.resolve());

    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
    expect(currentNodes().map(({ id, position }) => ({ id, position }))).toEqual(positionsBeforeRefresh);
    expect(flowHarness.animationFrames).toHaveLength(0);
    expect(flowHarness.fitView).toHaveBeenCalledTimes(1);
  });

  it('preserves retained positions after a node drag', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    dragNode(draggedItemsNode);
    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(reconcileUserArrangedNodes).toHaveBeenCalled());
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
    expect(currentNode('items')?.position).toEqual(draggedItemsNode.position);
  });

  it('preserves retained coordinates when a table is removed in user-arranged mode', async () => {
    const view = renderGraph(['items', 'holdings']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    dragNode(draggedItemsNode);

    view.rerender(graphElement(['items']));
    await waitFor(() => expect(reconcileUserArrangedNodes).toHaveBeenCalled());

    expect(currentNode('items')?.position).toEqual(draggedItemsNode.position);
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
  });

  it('refits after Re-layout and resumes automatic layout for later topology changes', async () => {
    const user = userEvent.setup();
    const view = renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    dragNode(draggedItemsNode);
    await user.click(screen.getByRole('button', { name: 'Re-layout relationship graph' }));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    flushAnimationFrames();
    expect(flowHarness.fitView).toHaveBeenCalledTimes(1);

    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(3));
  });

  it('completes an explicit Re-layout across an equivalent cloned refresh', async () => {
    const user = userEvent.setup();
    const view = renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    dragNode(draggedItemsNode);
    const explicitLayout = deferredLayout();
    vi.mocked(layoutRelationshipGraph).mockReturnValueOnce(explicitLayout.promise);

    await user.click(screen.getByRole('button', { name: 'Re-layout relationship graph' }));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
    view.rerender(graphElementWithData(
      structuredClone(['items']),
      structuredClone(tableDetails),
      structuredClone(tables),
    ));
    await act(async () => Promise.resolve());
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2);

    await act(async () => explicitLayout.resolve(layoutWithIds(['items'])));
    await waitFor(() => expect(screen.queryByText('Manual layout preserved')).not.toBeInTheDocument());
    expect(currentNode('items')?.position).toEqual({ x: 0, y: 0 });
  });

  it('lays out the latest topology when it changes during an explicit Re-layout', async () => {
    const user = userEvent.setup();
    const view = renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    dragNode(draggedItemsNode);
    const staleExplicitLayout = deferredLayout();
    vi.mocked(layoutRelationshipGraph).mockReturnValueOnce(staleExplicitLayout.promise);

    await user.click(screen.getByRole('button', { name: 'Re-layout relationship graph' }));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(3));
    await waitFor(() => expect(renderedNodeIds()).toEqual(['holdings', 'items']));

    await act(async () => staleExplicitLayout.resolve(layoutWithIds(['items'])));
    expect(renderedNodeIds()).toEqual(['holdings', 'items']);
    expect(screen.queryByText('Manual layout preserved')).not.toBeInTheDocument();
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

  it('installs the latest topology when a drag cancels its pending automatic layout', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    expect(currentNode('holdings')?.data.isSelected).toBe(false);

    const promotedLayout = deferredLayout();
    vi.mocked(layoutRelationshipGraph).mockReturnValueOnce(promotedLayout.promise);
    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
    dragNode(draggedItemsNode);

    expect(currentNode('holdings')?.data.isSelected).toBe(true);
    expect(currentNode('items')?.position).toEqual(draggedItemsNode.position);
  });

  it('preserves a ghost position when automatic layout promotes it to selected', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    const beforePromotion = currentNode('holdings')?.position;
    vi.mocked(layoutRelationshipGraph).mockImplementationOnce(async ({ nodes, edges }) => ({
      nodes: nodes.map((node) => ({
        ...node,
        position: node.id === 'holdings' ? { x: 900, y: 400 } : node.position,
      })),
      edges,
    }));
    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(currentNode('holdings')?.data.isSelected).toBe(true));

    expect(beforePromotion).toBeDefined();
    expect(currentNode('holdings')?.position).toEqual(beforePromotion);
  });

  it('disables viewport animation when reduced motion is preferred', async () => {
    flowHarness.reducedMotion = true;
    renderGraph(['items']);
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    flushAnimationFrames();

    expect(flowHarness.fitView).toHaveBeenCalledWith({ padding: 0.4, maxZoom: 1.2, duration: 0 });
  });

  it('cancels a delayed viewport fit when topology changes', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    const nextLayout = deferredLayout();
    vi.mocked(layoutRelationshipGraph).mockReturnValueOnce(nextLayout.promise);
    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(2));
    flushAnimationFrames();

    expect(flowHarness.fitView).not.toHaveBeenCalled();
  });

  it('cancels a delayed viewport fit after a node drag', async () => {
    renderGraph(['items']);
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    dragNode(draggedItemsNode);
    flushAnimationFrames();

    expect(flowHarness.fitView).not.toHaveBeenCalled();
  });

  it('cancels a delayed viewport fit on unmount', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(flowHarness.animationFrames).toHaveLength(1));
    view.unmount();
    flushAnimationFrames();

    expect(flowHarness.fitView).not.toHaveBeenCalled();
  });

  it('ignores a late layout result after unmount', async () => {
    const layout = deferredLayout();
    vi.mocked(layoutRelationshipGraph).mockReturnValueOnce(layout.promise);
    const view = renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    view.unmount();
    await act(async () => layout.resolve(layoutWithIds(['items', 'holdings'])));

    expect(flowHarness.animationFrames).toHaveLength(0);
    expect(flowHarness.fitView).not.toHaveBeenCalled();
  });
});
