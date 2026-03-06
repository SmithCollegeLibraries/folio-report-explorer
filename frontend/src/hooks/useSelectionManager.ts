import { useState, useEffect, useCallback } from 'react';

export interface UseSelectionManagerReturn {
  /** The currently selected IDs. */
  selectedIds: Set<string>;
  /** How many of the provided `selectableIds` are currently selected. */
  selectedCount: number;
  /** True when every selectable ID is selected (and there is at least one). */
  allChecked: boolean;
  /** Toggle the selection state of a single ID. */
  toggleOne: (id: string) => void;
  /** Select all when not all are selected; deselect all when all are selected. */
  toggleAll: () => void;
  /** Remove a set of IDs from the selection (e.g. after a batch delete). */
  removeIds: (ids: string[]) => void;
  /** Clear all selections. */
  clear: () => void;
}

/**
 * Generic checkbox-selection manager for a list of items.
 *
 * @param selectableIds  IDs that are eligible for selection in the current view.
 *                       When this changes, stale IDs are automatically pruned.
 */
export function useSelectionManager(selectableIds: string[]): UseSelectionManagerReturn {
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());

  // Prune selections that are no longer in the visible list
  useEffect(() => {
    setSelectedIds((prev) => {
      const valid = new Set(selectableIds);
      const pruned = new Set<string>();
      prev.forEach((id) => { if (valid.has(id)) pruned.add(id); });
      // Only trigger re-render when the set actually changed
      return pruned.size === prev.size ? prev : pruned;
    });
  }, [selectableIds]);

  const toggleOne = useCallback((id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }, []);

  const toggleAll = useCallback(() => {
    setSelectedIds((prev) => {
      const allSelected = selectableIds.length > 0 && selectableIds.every((id) => prev.has(id));
      const next = new Set(prev);
      if (allSelected) {
        selectableIds.forEach((id) => next.delete(id));
      } else {
        selectableIds.forEach((id) => next.add(id));
      }
      return next;
    });
  }, [selectableIds]);

  const removeIds = useCallback((ids: string[]) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      ids.forEach((id) => next.delete(id));
      return next;
    });
  }, []);

  const clear = useCallback(() => setSelectedIds(new Set()), []);

  const selectedCount = selectableIds.filter((id) => selectedIds.has(id)).length;
  const allChecked = selectableIds.length > 0 && selectedCount === selectableIds.length;

  return { selectedIds, selectedCount, allChecked, toggleOne, toggleAll, removeIds, clear };
}
