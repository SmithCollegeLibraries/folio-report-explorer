import { describe, expect, it, vi } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import { createRelationshipGraphLayout, layoutRelationshipGraph } from './builderGraphLayout';

const node = (id: string): Node => ({
  id,
  position: { x: 0, y: 0 },
  measured: { width: 180, height: 48 },
  data: { label: id },
});

const rectanglesOverlap = (left: Node, right: Node) => {
  const leftWidth = left.measured?.width ?? 190;
  const leftHeight = left.measured?.height ?? 48;
  const rightWidth = right.measured?.width ?? 190;
  const rightHeight = right.measured?.height ?? 48;

  return left.position.x < right.position.x + rightWidth
    && left.position.x + leftWidth > right.position.x
    && left.position.y < right.position.y + rightHeight
    && left.position.y + leftHeight > right.position.y;
};

describe('layoutRelationshipGraph', () => {
  it('orders a parent chain from left to right without overlap', async () => {
    const nodes = [node('items'), node('holdings'), node('instances')];
    const edges: Edge[] = [
      { id: 'items-holdings', source: 'items', target: 'holdings' },
      { id: 'holdings-instances', source: 'holdings', target: 'instances' },
    ];

    const result = await layoutRelationshipGraph({ nodes, edges, direction: 'RIGHT' });
    const positions = Object.fromEntries(result.nodes.map((entry) => [entry.id, entry.position]));

    expect(positions.items.x + 180).toBeLessThanOrEqual(positions.holdings.x);
    expect(positions.holdings.x + 180).toBeLessThanOrEqual(positions.instances.x);
    expect(rectanglesOverlap(result.nodes[0], result.nodes[1])).toBe(false);
    expect(rectanglesOverlap(result.nodes[1], result.nodes[2])).toBe(false);
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

    expect(rectanglesOverlap(result.nodes[0], result.nodes[1])).toBe(false);
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

  it('loads the layout engine on demand and reuses it', async () => {
    const layout = vi.fn(async (graph) => graph);
    const loadElk = vi.fn(async () => ({ layout }));
    const layoutGraph = createRelationshipGraphLayout(loadElk);

    expect(loadElk).not.toHaveBeenCalled();
    await layoutGraph({ nodes: [node('items')], edges: [], direction: 'RIGHT' });
    await layoutGraph({ nodes: [node('holdings')], edges: [], direction: 'RIGHT' });

    expect(loadElk).toHaveBeenCalledTimes(1);
    expect(layout).toHaveBeenCalledTimes(2);
  });

  it('retries loading the layout engine after a load failure', async () => {
    const layout = vi.fn(async (graph) => graph);
    const loadElk = vi.fn()
      .mockRejectedValueOnce(new Error('chunk failed to load'))
      .mockResolvedValueOnce({ layout });
    const layoutGraph = createRelationshipGraphLayout(loadElk);

    await expect(layoutGraph({ nodes: [node('items')], edges: [], direction: 'RIGHT' }))
      .rejects.toThrow('chunk failed to load');
    await expect(layoutGraph({ nodes: [node('items')], edges: [], direction: 'RIGHT' }))
      .resolves.toMatchObject({ nodes: [expect.objectContaining({ id: 'items' })] });

    expect(loadElk).toHaveBeenCalledTimes(2);
    expect(layout).toHaveBeenCalledTimes(1);
  });
});
