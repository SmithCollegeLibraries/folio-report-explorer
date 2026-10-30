import type { HistoryGroupListItem, HistoryGroupResponse } from '../api/history';

export interface UseHistoryGroupResult {
  expandedGroupIds: Set<string>;
  membersByGroup: Record<string, HistoryGroupResponse>;
  loadingGroupIds: Set<string>;
  toggleGroup: (group: HistoryGroupListItem) => Promise<void>;
  refreshOpenGroups: () => Promise<void>;
}

export function useHistoryGroup(
  _items: HistoryGroupListItem[],
  _status: string,
  _offset: number,
): UseHistoryGroupResult {
  throw new Error('not implemented');
}
