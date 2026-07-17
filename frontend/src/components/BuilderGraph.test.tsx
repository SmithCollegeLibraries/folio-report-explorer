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

const draggedItemsNode: Node = {
  id: 'items',
  position: { x: 480, y: 220 },
  data: { tableName: 'items', isSelected: true },
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    callback(0);
    return 0;
  });
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
