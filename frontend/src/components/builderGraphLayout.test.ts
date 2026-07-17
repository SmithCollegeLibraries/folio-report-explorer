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
