import { describe, expect, it } from 'vitest';
import type { CanonicalJoinEdge, CanonicalTableDetail } from '../types';
import {
  activeRelationship,
  applyRelationshipOverrides,
  currentRelationshipSelections,
  groupDirectRelationships,
  pruneRelationshipOverrides,
} from './builderRelationships';

const pairId = 'inventory.item__t<->inventory.location__t';
const effectiveId = 'inventory.item__t.effective_location_id->inventory.location__t.id';
const permanentId = 'inventory.item__t.permanent_location_id->inventory.location__t.id';
const temporaryId = 'inventory.item__t.temporary_location_id->inventory.location__t.id';

function relationship(
  relationshipId: string,
  fromColumn: string,
  isDefault = false,
) {
  return {
    from_table: 'inventory.item__t',
    from_column: fromColumn,
    to_table: 'inventory.location__t',
    to_column: 'id',
    parent_table: 'inventory.location__t',
    parent_column: 'id',
    local_column: fromColumn,
    foreign_key: `item_${fromColumn}_fk`,
    relationship_id: relationshipId,
    pair_id: pairId,
    label: fromColumn,
    is_default: isDefault,
    source: 'overlay' as const,
  };
}

const relationships = [
  relationship(permanentId, 'permanent_location_id'),
  relationship(effectiveId, 'effective_location_id', true),
  relationship(temporaryId, 'temporary_location_id'),
];

const tableDetails: Record<string, CanonicalTableDetail> = {
  'inventory.item__t': {
    name: 'inventory.item__t',
    table: {
      type: 'table', schema: 'inventory', remarks: null, primary_key: 'id', columns: [], indexes: [],
    },
    relationships: { parents: relationships, children: [] },
  },
  'inventory.location__t': {
    name: 'inventory.location__t',
    table: {
      type: 'table', schema: 'inventory', remarks: null, primary_key: 'id', columns: [], indexes: [],
    },
    // Duplicate copies returned by the other table must not duplicate options.
    relationships: { parents: [], children: relationships },
  },
};

const defaultJoin: CanonicalJoinEdge = {
  from_table: 'inventory.item__t',
  from_column: 'effective_location_id',
  to_table: 'inventory.location__t',
  to_column: 'id',
  foreign_key: 'item_effective_location_id_fk',
  relationship_id: effectiveId,
  pair_id: pairId,
  join_type: 'LEFT JOIN',
};

describe('builder relationship state', () => {
  it('groups direct alternatives once and chooses the catalog default', () => {
    const groups = groupDirectRelationships(tableDetails, [
      'inventory.item__t',
      'inventory.location__t',
    ]);
    const pair = groups[pairId];

    expect(pair.defaultRelationshipId).toBe(effectiveId);
    expect(pair.relationships.map((item) => item.relationship_id)).toEqual([
      effectiveId,
      permanentId,
      temporaryId,
    ]);
    expect(activeRelationship(pair, {})).toMatchObject({ from_column: 'effective_location_id' });
    expect(activeRelationship(pair, { [pair.pairId]: permanentId })).toMatchObject({
      from_column: 'permanent_location_id',
    });
    expect(activeRelationship(pair, { [pair.pairId]: 'missing' })).toMatchObject({
      from_column: 'effective_location_id',
    });
  });

  it('prunes overrides when a selected endpoint or relationship disappears', () => {
    const groups = groupDirectRelationships(tableDetails, Object.keys(tableDetails));
    expect(pruneRelationshipOverrides({ [pairId]: permanentId }, ['inventory.item__t'], groups))
      .toEqual({});
    expect(pruneRelationshipOverrides({ [pairId]: 'missing' }, Object.keys(tableDetails), groups))
      .toEqual({});
    expect(pruneRelationshipOverrides({ [pairId]: permanentId }, Object.keys(tableDetails), groups))
      .toEqual({ [pairId]: permanentId });
  });

  it('substitutes only the matching direct join and preserves its join type', () => {
    const groups = groupDirectRelationships(tableDetails, Object.keys(tableDetails));
    const unrelated: CanonicalJoinEdge = {
      from_table: 'inventory.holdings_record__t', from_column: 'id',
      to_table: 'inventory.item__t', to_column: 'holdings_record_id',
      foreign_key: 'holdings_item_fk', relationship_id: 'holdings->item',
      pair_id: 'inventory.holdings_record__t<->inventory.item__t', join_type: 'JOIN',
    };

    expect(applyRelationshipOverrides(
      [defaultJoin, unrelated],
      groups,
      { [pairId]: permanentId },
    )).toEqual([
      expect.objectContaining({
        relationship_id: permanentId,
        from_column: 'permanent_location_id',
        join_type: 'LEFT JOIN',
      }),
      unrelated,
    ]);
  });

  it('returns trusted selections for the discovered path with active alternatives and join types', () => {
    const groups = groupDirectRelationships(tableDetails, Object.keys(tableDetails));
    expect(currentRelationshipSelections(
      [defaultJoin],
      groups,
      { [pairId]: temporaryId },
      [{ ...defaultJoin, join_type: 'JOIN' }],
    )).toEqual([{ relationship_id: temporaryId, join_type: 'JOIN' }]);
  });
});
