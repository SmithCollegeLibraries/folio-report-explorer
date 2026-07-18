import type {
  CanonicalJoinEdge,
  CanonicalRelationship,
  RelationshipSelection,
  TableDetail,
} from '../types';

export interface RelationshipGroup {
  pairId: string;
  leftTable: string;
  rightTable: string;
  defaultRelationshipId: string;
  relationships: CanonicalRelationship[];
}

export type RelationshipGroups = Record<string, RelationshipGroup>;
export type RelationshipOverrides = Record<string, string>;

type RelationshipEndpoints = CanonicalRelationship & {
  from_table: string;
  from_column: string;
  to_table: string;
  to_column: string;
};

function endpoints(
  relationship: CanonicalRelationship,
  tableName: string,
  direction: 'parents' | 'children',
): RelationshipEndpoints | null {
  const candidate = relationship as Partial<RelationshipEndpoints>;
  if (
    candidate.from_table && candidate.from_column
    && candidate.to_table && candidate.to_column
  ) {
    return {
      ...relationship,
      from_table: candidate.from_table,
      from_column: candidate.from_column,
      to_table: candidate.to_table,
      to_column: candidate.to_column,
      parent_table: candidate.to_table,
      parent_column: candidate.to_column,
      child_table: candidate.from_table,
      child_column: candidate.from_column,
      local_column: candidate.from_column,
    } as RelationshipEndpoints;
  }

  if (direction === 'parents' && relationship.parent_table && relationship.parent_column) {
    return {
      ...relationship,
      from_table: tableName,
      from_column: relationship.local_column,
      to_table: relationship.parent_table,
      to_column: relationship.parent_column,
    };
  }
  if (direction === 'children' && relationship.child_table && relationship.child_column) {
    return {
      ...relationship,
      from_table: relationship.child_table,
      from_column: relationship.child_column,
      to_table: tableName,
      to_column: relationship.local_column,
    };
  }
  return null;
}

function pairTables(relationship: RelationshipEndpoints): [string, string] {
  return [relationship.from_table, relationship.to_table].sort() as [string, string];
}

/** Group the direct catalog relationships whose two endpoints are selected. */
export function groupDirectRelationships(
  tableDetails: Record<string, TableDetail>,
  selectedTables: string[],
): RelationshipGroups {
  const selected = new Set(selectedTables);
  const relationshipsByPair = new Map<string, Map<string, CanonicalRelationship>>();

  for (const tableName of selectedTables) {
    const detail = tableDetails[tableName];
    if (!detail) continue;

    for (const direction of ['parents', 'children'] as const) {
      for (const raw of detail.relationships[direction]) {
        if (!raw.relationship_id || !raw.pair_id) continue;
        const relationship = endpoints(raw as CanonicalRelationship, tableName, direction);
        if (
          !relationship
          || !selected.has(relationship.from_table)
          || !selected.has(relationship.to_table)
        ) continue;

        const byId = relationshipsByPair.get(relationship.pair_id) ?? new Map();
        byId.set(relationship.relationship_id, relationship);
        relationshipsByPair.set(relationship.pair_id, byId);
      }
    }
  }

  const groups: RelationshipGroups = {};
  for (const [pairId, byId] of relationshipsByPair) {
    const relationships = [...byId.values()].sort((left, right) => {
      if (left.is_default !== right.is_default) return left.is_default ? -1 : 1;
      return left.relationship_id.localeCompare(right.relationship_id);
    });
    if (relationships.length === 0) continue;
    const [leftTable, rightTable] = pairTables(relationships[0] as RelationshipEndpoints);
    groups[pairId] = {
      pairId,
      leftTable,
      rightTable,
      defaultRelationshipId: relationships.find((item) => item.is_default)?.relationship_id
        ?? relationships[0].relationship_id,
      relationships,
    };
  }
  return groups;
}

export function activeRelationship(
  group: RelationshipGroup,
  overrides: RelationshipOverrides,
): CanonicalRelationship {
  const selectedId = overrides[group.pairId];
  return group.relationships.find((item) => item.relationship_id === selectedId)
    ?? group.relationships.find((item) => item.relationship_id === group.defaultRelationshipId)
    ?? group.relationships[0];
}

export function pruneRelationshipOverrides(
  overrides: RelationshipOverrides,
  selectedTables: string[],
  groups?: RelationshipGroups,
): RelationshipOverrides {
  const selected = new Set(selectedTables);
  const next: RelationshipOverrides = {};
  for (const [pairId, relationshipId] of Object.entries(overrides)) {
    const group = groups?.[pairId];
    if (group) {
      if (
        selected.has(group.leftTable)
        && selected.has(group.rightTable)
        && group.relationships.some((item) => item.relationship_id === relationshipId)
      ) {
        next[pairId] = relationshipId;
      }
      continue;
    }

    const endpoints = pairId.split('<->');
    if (!groups && endpoints.length === 2 && endpoints.every((table) => selected.has(table))) {
      next[pairId] = relationshipId;
    }
  }
  return next;
}

function relationshipEdge(
  relationship: CanonicalRelationship,
  joinType: CanonicalJoinEdge['join_type'],
): CanonicalJoinEdge | null {
  const item = relationship as Partial<RelationshipEndpoints>;
  if (!item.from_table || !item.from_column || !item.to_table || !item.to_column) return null;
  return {
    from_table: item.from_table,
    from_column: item.from_column,
    to_table: item.to_table,
    to_column: item.to_column,
    foreign_key: relationship.foreign_key || relationship.label,
    relationship_id: relationship.relationship_id,
    pair_id: relationship.pair_id,
    ...(joinType ? { join_type: joinType } : {}),
  };
}

export function applyRelationshipOverrides(
  defaultJoins: CanonicalJoinEdge[],
  groups: RelationshipGroups,
  overrides: RelationshipOverrides,
): CanonicalJoinEdge[] {
  return defaultJoins.map((join) => {
    const group = groups[join.pair_id];
    if (!group || !overrides[join.pair_id]) return join;
    const relationship = activeRelationship(group, overrides);
    if (relationship.relationship_id === join.relationship_id) return join;
    return relationshipEdge(relationship, join.join_type) ?? join;
  });
}

export function currentRelationshipSelections(
  defaultJoins: CanonicalJoinEdge[],
  groups: RelationshipGroups,
  overrides: RelationshipOverrides,
  customJoins: CanonicalJoinEdge[],
): RelationshipSelection[] {
  const joinTypes = new Map(customJoins.map((join) => [join.pair_id, join.join_type]));
  return applyRelationshipOverrides(defaultJoins, groups, overrides).map((join) => {
    const joinType = joinTypes.get(join.pair_id) ?? join.join_type;
    return {
      relationship_id: join.relationship_id,
      ...(joinType ? { join_type: joinType } : {}),
    };
  });
}
