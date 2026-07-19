import type { HistoryItem } from '../../types';

export interface HistoryDeletionState {
  items: HistoryItem[];
  total: number;
  offset: number;
  closeModal: boolean;
}

export function isDeletableHistoryItem(item: HistoryItem): boolean {
  const isTerminal = item.status === 'completed'
    || item.status === 'failed'
    || item.status === 'cancelled';
  return item.canDelete && isTerminal;
}

export function deriveHistoryDeletionState(
  items: HistoryItem[],
  total: number,
  offset: number,
  limit: number,
  deletedIds: string[],
  modalJobId: string | null,
): HistoryDeletionState {
  const deletedIdSet = new Set(deletedIds);
  const pageEmptied = items.length > 0 && items.every((item) => deletedIdSet.has(item.jobId));

  return {
    items: items.filter((item) => !deletedIdSet.has(item.jobId)),
    total: Math.max(0, total - deletedIds.length),
    offset: pageEmptied && offset > 0 ? Math.max(0, offset - limit) : offset,
    closeModal: modalJobId !== null && deletedIdSet.has(modalJobId),
  };
}
