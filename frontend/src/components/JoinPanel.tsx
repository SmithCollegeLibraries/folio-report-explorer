import { useState, useEffect } from 'react';
import { findPath } from '../api/client';
import type { JoinEdge, JoinType, TableDetail } from '../types';
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
  tableDetails: Record<string, TableDetail>;
  joinMode: 'auto' | 'manual';
  customJoins: JoinEdge[];
  onJoinModeChange: (mode: 'auto' | 'manual') => void;
  onCustomJoinsChange: (joins: JoinEdge[]) => void;
}

export default function JoinPanel({
  selectedTables,
  joinMode,
  customJoins,
  onJoinModeChange,
  onCustomJoinsChange,
}: Props) {
  const [discoveredJoins, setDiscoveredJoins] = useState<JoinEdge[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Auto-discover joins between selected tables using BFS path finder
  useEffect(() => {
    if (selectedTables.length < 2) {
      setDiscoveredJoins([]);
      setError(null);
      return;
    }

    let cancelled = false;
    setLoading(true);
    setError(null);

    async function discover() {
      const joins: JoinEdge[] = [];
      const joined = new Set<string>([selectedTables[0]]);

      for (let i = 1; i < selectedTables.length; i++) {
        const target = selectedTables[i];
        if (joined.has(target)) continue;

        let bestPath: JoinEdge[] | null = null;
        for (const source of joined) {
          try {
            const resp = await findPath(source, target);
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
          if (!cancelled) {
            setError(`Cannot find FK path to "${target}"`);
          }
        }

        joined.add(target);
      }

      if (!cancelled) {
        setDiscoveredJoins(joins);
        // If switching to auto mode and custom joins are empty, populate them
        if (customJoins.length === 0 && joins.length > 0) {
          onCustomJoinsChange(joins.map((j) => ({ ...j })));
        }
        setLoading(false);
      }
    }

    discover();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedTables.join(',')]);

  // The joins to display — in auto mode show discovered, in manual show custom
  const displayJoins = joinMode === 'auto' ? discoveredJoins : customJoins;

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
    onJoinModeChange('auto');
    onCustomJoinsChange(discoveredJoins.map((j) => ({ ...j, join_type: 'JOIN' })));
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
              joinMode === 'auto' ? onJoinModeChange('manual') : resetToAuto()
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
          {displayJoins.map((j, i) => (
            <div
              key={i}
              className="flex items-center gap-3 bg-gray-50 rounded-lg px-4 py-3 border"
            >
              {/* From */}
              <div className="flex items-center gap-1.5 min-w-0">
                <span className="text-xs font-mono font-semibold text-gray-700 truncate">
                  {j.from_table}
                </span>
                <span className="text-xs text-gray-400">.{j.from_column}</span>
              </div>

              {/* Join type selector */}
              <div className="flex-shrink-0">
                <select
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
                <span className="text-xs text-gray-400">.{j.to_column}</span>
              </div>

              {/* FK name */}
              {j.foreign_key && (
                <span className="text-[10px] text-gray-400 ml-auto truncate font-mono">
                  {j.foreign_key}
                </span>
              )}
            </div>
          ))}
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
