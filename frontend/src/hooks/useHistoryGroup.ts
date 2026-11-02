import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchHistoryGroup } from '../api/history';
import type { HistoryGroupListItem, HistoryGroupResponse } from '../api/history';

export interface UseHistoryGroupResult {
  expandedGroupIds: Set<string>;
  membersByGroup: Record<string, HistoryGroupResponse>;
  loadingGroupIds: Set<string>;
  toggleGroup: (group: HistoryGroupListItem) => Promise<void>;
  refreshOpenGroups: () => Promise<void>;
  setGroupOffset: (group: HistoryGroupListItem, offset: number) => Promise<void>;
}

const MEMBER_PAGE_SIZE = 20;
const itemSignature = (items: HistoryGroupListItem[]) => items.map((item) => [item.jobId, item.groupId, item.status, item.runCount, item.activeRunCount].join(':')).join('|');

export function useHistoryGroup(
  items: HistoryGroupListItem[],
  status: string,
  offset: number,
  refreshSequence = 0,
): UseHistoryGroupResult {
  const [expandedGroupIds, setExpandedGroupIds] = useState<Set<string>>(new Set());
  const [membersByGroup, setMembersByGroup] = useState<Record<string, HistoryGroupResponse>>({});
  const [loadingGroupIds, setLoadingGroupIds] = useState<Set<string>>(new Set());
  const expandedRef = useRef(expandedGroupIds);
  const membersRef = useRef(membersByGroup);
  const itemsRef = useRef(items);
  const viewRef = useRef({ status, offset });
  const previousItemsRef = useRef(items);
  const signatureRef = useRef(itemSignature(items));
  const refreshSequenceRef = useRef(refreshSequence);

  expandedRef.current = expandedGroupIds;
  membersRef.current = membersByGroup;
  itemsRef.current = items;

  const fetchMembers = useCallback(async (group: HistoryGroupListItem, requestedOffset: number) => {
    if (!group.groupId) return;
    const groupId = group.groupId;
    setLoadingGroupIds((previous) => new Set(previous).add(groupId));
    try {
      let response = await fetchHistoryGroup(group.jobId, {
        status: viewRef.current.status,
        limit: MEMBER_PAGE_SIZE,
        offset: requestedOffset,
      });
      const lastOffset = Math.max(0, Math.floor(Math.max(0, response.total - 1) / MEMBER_PAGE_SIZE) * MEMBER_PAGE_SIZE);
      if (requestedOffset > lastOffset) {
        response = await fetchHistoryGroup(group.jobId, {
          status: viewRef.current.status,
          limit: MEMBER_PAGE_SIZE,
          offset: lastOffset,
        });
      }
      setMembersByGroup((previous) => ({ ...previous, [groupId]: response }));
    } finally {
      setLoadingGroupIds((previous) => {
        const next = new Set(previous);
        next.delete(groupId);
        return next;
      });
    }
  }, []);

  const toggleGroup = useCallback(async (group: HistoryGroupListItem) => {
    if (!group.groupId) return;
    const groupId = group.groupId;
    if (expandedRef.current.has(groupId)) {
      setExpandedGroupIds((previous) => {
        const next = new Set(previous);
        next.delete(groupId);
        return next;
      });
      setMembersByGroup((previous) => {
        const next = { ...previous };
        delete next[groupId];
        return next;
      });
      return;
    }
    setExpandedGroupIds((previous) => new Set(previous).add(groupId));
    await fetchMembers(group, 0);
  }, [fetchMembers]);

  const refreshOpenGroups = useCallback(async () => {
    const open = Array.from(expandedRef.current);
    await Promise.all(open.map((groupId) => {
      const group = itemsRef.current.find((item) => item.groupId === groupId);
      if (!group) return Promise.resolve();
      return fetchMembers(group, membersRef.current[groupId]?.offset ?? 0);
    }));
  }, [fetchMembers]);

  const setGroupOffset = useCallback((group: HistoryGroupListItem, nextOffset: number) => (
    fetchMembers(group, Math.max(0, Math.floor(nextOffset / MEMBER_PAGE_SIZE) * MEMBER_PAGE_SIZE))
  ), [fetchMembers]);

  useEffect(() => {
    const previousView = viewRef.current;
    viewRef.current = { status, offset };
    if (previousView.status !== status || previousView.offset !== offset) {
      setExpandedGroupIds(new Set());
      setMembersByGroup({});
      setLoadingGroupIds(new Set());
      expandedRef.current = new Set();
      previousItemsRef.current = items;
      signatureRef.current = itemSignature(items);
      refreshSequenceRef.current = refreshSequence;
      return;
    }
    const signature = itemSignature(items);
    const changed = signatureRef.current !== signature || refreshSequenceRef.current !== refreshSequence;
    if (previousItemsRef.current !== items) {
      const available = new Set(items.map((item) => item.groupId).filter((id): id is string => !!id));
      const retained = new Set(Array.from(expandedRef.current).filter((id) => available.has(id)));
      if (retained.size !== expandedRef.current.size) {
        expandedRef.current = retained;
        setExpandedGroupIds(retained);
        setMembersByGroup((previous) => Object.fromEntries(
          Object.entries(previous).filter(([id]) => retained.has(id)),
        ));
      }
      previousItemsRef.current = items;
    }
    if (changed) {
      items.filter((item) => item.groupId && expandedRef.current.has(item.groupId)).forEach((group) => {
        void fetchMembers(group, membersRef.current[group.groupId!]?.offset ?? 0);
      });
      signatureRef.current = signature;
      refreshSequenceRef.current = refreshSequence;
    }
  }, [items, status, offset, refreshSequence, fetchMembers]);

  return { expandedGroupIds, membersByGroup, loadingGroupIds, toggleGroup, refreshOpenGroups, setGroupOffset };
}
