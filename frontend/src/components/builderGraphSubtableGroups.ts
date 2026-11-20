import {
  MarkerType,
  type Edge,
  type Node,
} from '@xyflow/react';
import type { TableDetail, TableSummary } from '../types';

export interface ConnectedTable {
  name: string;
  connections: {
    fromTable: string;
    column: string;
    toColumn: string;
    direction: 'parent' | 'child';
  }[];
}

export interface SubtableGroup {
  parentName: string;
  parentShortName: string;
  label: string;
  subtables: { name: string; shortName: string }[];
}

function getConnectedTables(
  selectedTables: string[],
  tableDetails: Record<string, TableDetail>,
  tables: Record<string, TableSummary>,
): ConnectedTable[] {
  const selected = new Set(selectedTables);
  const map = new Map<string, ConnectedTable['connections']>();

  for (const selectedTable of selectedTables) {
    const detail = tableDetails[selectedTable];
    if (!detail?.relationships) continue;

    for (const relationship of detail.relationships.parents || []) {
      const parentName = relationship.parent_table;
      if (!parentName || !tables[parentName] || selected.has(parentName)) continue;
      if (!map.has(parentName)) map.set(parentName, []);
      map.get(parentName)!.push({
        fromTable: selectedTable,
        column: relationship.local_column,
        toColumn: relationship.parent_column || 'id',
        direction: 'parent',
      });
    }
    for (const relationship of detail.relationships.children || []) {
      const childName = relationship.child_table;
      if (!childName || !tables[childName] || selected.has(childName)) continue;
      if (!map.has(childName)) map.set(childName, []);
      map.get(childName)!.push({
        fromTable: selectedTable,
        column: relationship.local_column,
        toColumn: relationship.child_column || relationship.local_column,
        direction: 'child',
      });
    }
  }

  const result = Array.from(map, ([name, connections]) => ({ name, connections }));
  return result.sort((left, right) => right.connections.length - left.connections.length);
}

function resolveSelectedParentName(
  subtableName: string,
  parentTable: string | undefined,
  selectedTables: string[],
  tables: Record<string, TableSummary>,
): string | undefined {
  if (parentTable) {
    for (const selectedName of selectedTables) {
      const selected = tables[selectedName];
      if (
        parentTable === selectedName
        || parentTable === selected?.name
        || parentTable === selected?.sql_name
        || parentTable === selected?.alias_name
      ) return selectedName;
    }
  }

  const marker = '__t__';
  const markerIndex = subtableName.indexOf(marker);
  if (markerIndex < 0) return undefined;
  const derivedName = `${subtableName.slice(0, markerIndex)}__t`;
  return selectedTables.includes(derivedName) ? derivedName : undefined;
}

export function groupConnectedTables(
  selectedTables: string[],
  tableDetails: Record<string, TableDetail>,
  tables: Record<string, TableSummary>,
): { ordinaryTables: ConnectedTable[]; subtableGroups: SubtableGroup[] } {
  const ordinaryTables: ConnectedTable[] = [];
  const subtablesByParent = new Map<string, SubtableGroup['subtables']>();

  for (const connected of getConnectedTables(selectedTables, tableDetails, tables)) {
    const table = tables[connected.name];
    if (table?.type !== 'SUBTABLE') {
      ordinaryTables.push(connected);
      continue;
    }

    const parentName = resolveSelectedParentName(
      connected.name,
      table.parent_table,
      selectedTables,
      tables,
    );
    if (!parentName) {
      ordinaryTables.push(connected);
      continue;
    }
    const prefix = `${parentName}__`;
    const shortName = connected.name.startsWith(prefix)
      ? connected.name.slice(prefix.length)
      : connected.name;
    if (!subtablesByParent.has(parentName)) subtablesByParent.set(parentName, []);
    subtablesByParent.get(parentName)!.push({ name: connected.name, shortName });
  }

  const subtableGroups = Array.from(subtablesByParent, ([parentName, subtables]) => {
    subtables.sort((left, right) => left.shortName.localeCompare(right.shortName));
    const count = subtables.length;
    return {
      parentName,
      parentShortName: parentName.includes('.') ? parentName.slice(parentName.indexOf('.') + 1) : parentName,
      label: `+ ${count} subtable${count === 1 ? '' : 's'}`,
      subtables,
    };
  });

  return { ordinaryTables, subtableGroups };
}

export function createSubtableGroupGraph(groups: SubtableGroup[]): { nodes: Node[]; edges: Edge[] } {
  const nodes: Node[] = [];
  const edges: Edge[] = [];

  for (const group of groups) {
    const id = `subtable-group:${group.parentName}`;
    nodes.push({
      id,
      data: { label: group.label, isSubtableGroup: true, parentName: group.parentName },
      position: { x: 60, y: 60 },
      type: 'subtableGroup',
      focusable: false,
      style: {
        background: '#ffffff',
        color: '#6b7280',
        border: '2px dashed #9ca3af',
        borderRadius: '10px',
        padding: '10px 18px',
        fontFamily: 'ui-monospace, monospace',
        fontSize: '12px',
        minWidth: '150px',
        width: 'max-content',
        whiteSpace: 'nowrap',
        textAlign: 'center' as const,
        cursor: 'pointer',
      },
      domAttributes: { title: `Subtables of ${group.parentShortName}` },
    });
    edges.push({
      id: `subtable-group-edge:${group.parentName}`,
      source: group.parentName,
      target: id,
      style: { strokeWidth: 1.5, stroke: '#9ca3af', strokeDasharray: '6 4' },
      markerEnd: { type: MarkerType.ArrowClosed, color: '#9ca3af', width: 14, height: 14 },
      type: 'smoothstep',
    });
  }

  return { nodes, edges };
}
