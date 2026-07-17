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
