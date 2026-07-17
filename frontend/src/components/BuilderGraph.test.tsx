import React from 'react';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import type { TableDetail, TableSummary } from '../types';
import BuilderGraph from './BuilderGraph';
import { layoutRelationshipGraph } from './builderGraphLayout';
import { reconcileUserArrangedNodes } from './builderGraphPositions';

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

  it('preserves retained positions after a node drag', async () => {
    const view = renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    dragNode(draggedItemsNode);
    view.rerender(graphElement(['items', 'holdings']));
    await waitFor(() => expect(reconcileUserArrangedNodes).toHaveBeenCalled());
    expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1);
    expect(currentNode('items')?.position).toEqual(draggedItemsNode.position);
  });

  it('runs full layout again only after Re-layout is clicked', async () => {
    const user = userEvent.setup();
    renderGraph(['items']);
    await waitFor(() => expect(layoutRelationshipGraph).toHaveBeenCalledTimes(1));
    dragNode(draggedItemsNode);
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
