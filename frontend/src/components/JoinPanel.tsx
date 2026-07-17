import { useState, useEffect } from 'react';
import { findPath } from '../api/client';
import type { CanonicalJoinEdge, JoinEdge, JoinType } from '../types';
import type { RelationshipGroups, RelationshipOverrides } from './builderRelationships';
import { activeRelationship, applyRelationshipOverrides } from './builderRelationships';
import {
  Link2,
  ArrowRight,
  ToggleLeft,
  ToggleRight,
  Loader2,
  AlertCircle,
} from 'lucide-react';

interface Props {
  selectedTables: string[];
  joinMode: 'auto' | 'manual';
  customJoins: JoinEdge[];
  relationshipGroups: RelationshipGroups;
  activeRelationshipOverrides: RelationshipOverrides;
  onRelationshipChange: (pairId: string, relationshipId: string) => void;
  onResetRelationships: () => void;
  onDefaultJoinsChange: (joins: CanonicalJoinEdge[]) => void;
  onJoinModeChange: (mode: 'auto' | 'manual') => void;
  onCustomJoinsChange: (joins: JoinEdge[]) => void;
}

export default function JoinPanel({
  selectedTables,
  joinMode,
  customJoins,
  relationshipGroups,
  activeRelationshipOverrides,
  onRelationshipChange,
  onResetRelationships,
  onJoinModeChange,
  onCustomJoinsChange,
  onDefaultJoinsChange,
}: Props) {
  const [discoveredJoins, setDiscoveredJoins] = useState<CanonicalJoinEdge[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Auto-discover joins between selected tables using BFS path finder
  useEffect(() => {
    if (selectedTables.length < 2) {
      setDiscoveredJoins([]);
      onDefaultJoinsChange([]);
      setLoading(false);
      setError(null);
      return;
    }

    let cancelled = false;
    setDiscoveredJoins([]);
    onDefaultJoinsChange([]);
    setLoading(true);
    setError(null);

    async function discover() {
      const joins: CanonicalJoinEdge[] = [];
      const joined = new Set<string>([selectedTables[0]]);
      let complete = true;

      for (let i = 1; i < selectedTables.length; i++) {
        const target = selectedTables[i];
        if (joined.has(target)) continue;

        let bestPath: CanonicalJoinEdge[] | null = null;
        for (const source of joined) {
          try {
            const resp = await findPath(source, target, false, 6, 'ldlite');
            if (resp.path && resp.path.joins.length > 0) {
              if (!bestPath || resp.path.joins.length < bestPath.length) {
                bestPath = resp.path.joins;
              }
            }
          } catch {
            // try next source
          }
        }

        if (bestPath) {
          for (const edge of bestPath) {
            const exists = joins.some(
              (j) =>
                j.from_table === edge.from_table &&
                j.to_table === edge.to_table &&
                j.from_column === edge.from_column &&
                j.to_column === edge.to_column,
            );
            if (!exists) {
              joins.push({ ...edge, join_type: 'JOIN' });
            }
          }
          // Mark all intermediate tables as joined
          for (const edge of bestPath) {
            joined.add(edge.to_table);
            joined.add(edge.from_table);
          }
        } else {
          complete = false;
          if (!cancelled) {
            setError(`Cannot find FK path to "${target}"`);
          }
          break;
        }

        joined.add(target);
      }

      if (!cancelled) {
        const completeJoins = complete ? joins : [];
        setDiscoveredJoins(completeJoins);
        onDefaultJoinsChange(completeJoins.map((join) => ({ ...join })));
        setLoading(false);
      }
    }

    discover();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedTables.join(','), onDefaultJoinsChange]);

  // The joins to display — in auto mode show discovered, in manual show custom
  const baseDisplayJoins = joinMode === 'auto'
    ? discoveredJoins
    : customJoins.filter((join): join is CanonicalJoinEdge => (
      typeof join.relationship_id === 'string' && typeof join.pair_id === 'string'
    ));
  const displayJoins = applyRelationshipOverrides(
    baseDisplayJoins,
    relationshipGroups,
    activeRelationshipOverrides,
  );

  const updateJoinType = (index: number, type: JoinType) => {
    if (joinMode === 'auto') {
      // Switch to manual mode to allow customization
      const newJoins = discoveredJoins.map((j, i) =>
        i === index ? { ...j, join_type: type } : { ...j },
      );
      onCustomJoinsChange(newJoins);
      onJoinModeChange('manual');
    } else {
      const newJoins = customJoins.map((j, i) =>
        i === index ? { ...j, join_type: type } : j,
      );
      onCustomJoinsChange(newJoins);
    }
  };

  const resetToAuto = () => {
    onResetRelationships();
    onJoinModeChange('auto');
    onCustomJoinsChange(discoveredJoins.map((j) => ({ ...j, join_type: 'JOIN' })));
  };

  const enterManualMode = () => {
    if (customJoins.length === 0 && discoveredJoins.length > 0) {
      onCustomJoinsChange(discoveredJoins.map((join) => ({
        ...join,
        join_type: join.join_type || 'JOIN',
      })));
    }
    onJoinModeChange('manual');
  };

  if (selectedTables.length < 2) {
    return (
      <div className="p-6 text-center text-gray-400 text-sm">
        Select at least 2 tables to configure joins.
      </div>
    );
  }

  return (
    <div className="p-4 space-y-4">
      {/* Mode toggle */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Link2 size={16} className="text-folio-600" />
          <span className="text-sm font-semibold text-gray-700">Join Configuration</span>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={() =>
              joinMode === 'auto' ? enterManualMode() : resetToAuto()
            }
            className={`flex items-center gap-1.5 text-xs px-3 py-1.5 rounded border transition-colors ${
              joinMode === 'auto'
                ? 'bg-folio-50 text-folio-700 border-folio-300'
                : 'text-gray-500 border-gray-200 hover:border-gray-300'
            }`}
          >
            {joinMode === 'auto' ? (
              <ToggleRight size={14} />
            ) : (
              <ToggleLeft size={14} />
            )}
            Auto Joins
          </button>
          {joinMode === 'manual' && (
            <button
              onClick={resetToAuto}
              className="text-xs text-folio-600 hover:text-folio-800 underline"
            >
              Reset to auto
            </button>
          )}
        </div>
      </div>

      {/* Description */}
      <p className="text-xs text-gray-500">
        {joinMode === 'auto'
          ? 'Joins are automatically discovered using foreign key relationships. Click a join type to customize.'
          : 'Customize join types below. Change any join from INNER to LEFT JOIN.'}
      </p>

      {/* Loading */}
      {loading && (
        <div className="flex items-center gap-2 text-sm text-gray-500 py-4">
          <Loader2 size={16} className="animate-spin" />
          Discovering join paths…
        </div>
      )}

      {/* Error */}
      {error && (
        <div className="flex items-center gap-2 text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">
          <AlertCircle size={14} />
          {error}
        </div>
      )}

      {/* Join list */}
      {!loading && displayJoins.length > 0 && (
        <div className="space-y-2">
          {displayJoins.map((j, i) => {
            const group = relationshipGroups[j.pair_id];
            const selectedRelationship = group
              ? activeRelationship(group, activeRelationshipOverrides)
              : null;
            return (
            <div
              key={j.relationship_id || i}
              className="flex flex-wrap items-center gap-3 bg-gray-50 rounded-lg px-4 py-3 border"
            >
              {/* From */}
              <div className="flex items-center gap-1.5 min-w-0">
                <span className="text-xs font-mono font-semibold text-gray-700 truncate">
                  {j.from_table}
                </span>
                <span className="text-xs text-gray-400">.</span>
                <span
                  className="text-xs text-gray-400"
                  data-testid={`join-from-column-${j.pair_id}`}
                >
                  {j.from_column}
                </span>
              </div>

              {/* Join type selector */}
              <div className="flex-shrink-0">
                <select
                  aria-label={`Join type for ${j.from_table} and ${j.to_table}`}
                  value={j.join_type || 'JOIN'}
                  onChange={(e) => updateJoinType(i, e.target.value as JoinType)}
                  className={`text-xs font-semibold px-2 py-1 rounded border cursor-pointer ${
                    (j.join_type || 'JOIN') === 'LEFT JOIN'
                      ? 'bg-amber-50 text-amber-700 border-amber-300'
                      : 'bg-folio-50 text-folio-700 border-folio-300'
                  }`}
                >
                  <option value="JOIN">INNER JOIN</option>
                  <option value="LEFT JOIN">LEFT JOIN</option>
                </select>
              </div>

              {/* Arrow */}
              <ArrowRight size={14} className="text-gray-400 flex-shrink-0" />

              {/* To */}
              <div className="flex items-center gap-1.5 min-w-0">
                <span className="text-xs font-mono font-semibold text-gray-700 truncate">
                  {j.to_table}
                </span>
                <span className="text-xs text-gray-400">.</span>
                <span
                  className="text-xs text-gray-400"
                  data-testid={`join-to-column-${j.pair_id}`}
                >
                  {j.to_column}
                </span>
              </div>

              {/* FK name */}
              {j.foreign_key && (
                <span className="text-[10px] text-gray-400 ml-auto truncate font-mono">
                  {j.foreign_key}
                </span>
              )}

              {group && group.relationships.length > 1 && selectedRelationship && (
                <label className="basis-full flex items-center gap-2 text-xs text-gray-600">
                  <span>Relationship</span>
                  <select
                    aria-label={`Relationship for ${group.leftTable} and ${group.rightTable}`}
                    value={selectedRelationship.relationship_id}
                    onChange={(event) => onRelationshipChange(group.pairId, event.target.value)}
                    className="min-w-0 flex-1 rounded border border-gray-300 bg-white px-2 py-1 font-mono text-xs text-gray-700"
                  >
                    {group.relationships.map((relationship) => (
                      <option
                        key={relationship.relationship_id}
                        value={relationship.relationship_id}
                      >
                        {relationship.label}{relationship.is_default ? ' — Default' : ''}
                      </option>
                    ))}
                  </select>
                </label>
              )}
            </div>
            );
          })}
        </div>
      )}

      {/* No joins discovered */}
      {!loading && displayJoins.length === 0 && selectedTables.length >= 2 && !error && (
        <div className="text-center text-gray-400 text-sm py-4">
          No join paths discovered between selected tables.
        </div>
      )}
    </div>
  );
}
