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
  const viewRef = useRef({ status, offset, generation: 0 });
  if (viewRef.current.status !== status || viewRef.current.offset !== offset) {
    viewRef.current = { status, offset, generation: viewRef.current.generation + 1 };
  }
  const previousViewRef = useRef({ status, offset });
  const requestGenerationsRef = useRef(new Map<string, number>());
  const requestedOffsetsRef = useRef(new Map<string, number>());
  const previousItemsRef = useRef(items);
  const signatureRef = useRef(itemSignature(items));
  const refreshSequenceRef = useRef(refreshSequence);

  expandedRef.current = expandedGroupIds;
  membersRef.current = membersByGroup;
  itemsRef.current = items;

  const fetchMembers = useCallback(async (group: HistoryGroupListItem, requestedOffset: number) => {
    if (!group.groupId) return;
    const groupId = group.groupId;
    requestedOffsetsRef.current.set(groupId, requestedOffset);
    const viewGeneration = viewRef.current.generation;
    const requestKey = `${groupId}:${viewGeneration}`;
    const requestGeneration = (requestGenerationsRef.current.get(requestKey) ?? 0) + 1;
    requestGenerationsRef.current.set(requestKey, requestGeneration);
    const isLatestRequest = () => requestGenerationsRef.current.get(requestKey) === requestGeneration
      && viewRef.current.generation === viewGeneration;
    const canApplyResponse = () => isLatestRequest() && expandedRef.current.has(groupId);
    setLoadingGroupIds((previous) => new Set(previous).add(groupId));
    try {
      let response = await fetchHistoryGroup(group.jobId, {
        status: viewRef.current.status,
        limit: MEMBER_PAGE_SIZE,
        offset: requestedOffset,
      });
      if (!canApplyResponse()) return;
      const lastOffset = Math.max(0, Math.floor(Math.max(0, response.total - 1) / MEMBER_PAGE_SIZE) * MEMBER_PAGE_SIZE);
      if (requestedOffset > lastOffset) {
        requestedOffsetsRef.current.set(groupId, lastOffset);
        response = await fetchHistoryGroup(group.jobId, {
          status: viewRef.current.status,
          limit: MEMBER_PAGE_SIZE,
          offset: lastOffset,
        });
        if (!canApplyResponse()) return;
      }
      requestedOffsetsRef.current.set(groupId, response.offset);
      setMembersByGroup((previous) => ({ ...previous, [groupId]: response }));
    } finally {
      if (isLatestRequest()) setLoadingGroupIds((previous) => {
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
      const nextExpanded = new Set(expandedRef.current);
      nextExpanded.delete(groupId);
      expandedRef.current = nextExpanded;
      requestedOffsetsRef.current.delete(groupId);
      setExpandedGroupIds(nextExpanded);
      setMembersByGroup((previous) => {
        const next = { ...previous };
        delete next[groupId];
        return next;
      });
      setLoadingGroupIds((previous) => {
        const next = new Set(previous);
        next.delete(groupId);
        return next;
      });
      return;
    }
    const nextExpanded = new Set(expandedRef.current).add(groupId);
    expandedRef.current = nextExpanded;
    setExpandedGroupIds(nextExpanded);
    await fetchMembers(group, 0);
  }, [fetchMembers]);

  const refreshOpenGroups = useCallback(async () => {
    const open = Array.from(expandedRef.current);
    await Promise.all(open.map((groupId) => {
      const group = itemsRef.current.find((item) => item.groupId === groupId);
      if (!group) return Promise.resolve();
      return fetchMembers(group, requestedOffsetsRef.current.get(groupId) ?? membersRef.current[groupId]?.offset ?? 0);
    }));
  }, [fetchMembers]);

  const setGroupOffset = useCallback((group: HistoryGroupListItem, nextOffset: number) => {
    const requestedOffset = Math.max(0, Math.floor(nextOffset / MEMBER_PAGE_SIZE) * MEMBER_PAGE_SIZE);
    if (group.groupId) requestedOffsetsRef.current.set(group.groupId, requestedOffset);
    return fetchMembers(group, requestedOffset);
  }, [fetchMembers]);

  useEffect(() => {
    const previousView = previousViewRef.current;
    if (previousView.status !== status || previousView.offset !== offset) {
      previousViewRef.current = { status, offset };
      requestGenerationsRef.current.clear();
      requestedOffsetsRef.current.clear();
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
        Array.from(requestedOffsetsRef.current.keys()).forEach((id) => {
          if (!retained.has(id)) requestedOffsetsRef.current.delete(id);
        });
        setExpandedGroupIds(retained);
        setMembersByGroup((previous) => Object.fromEntries(
          Object.entries(previous).filter(([id]) => retained.has(id)),
        ));
      }
      previousItemsRef.current = items;
    }
    if (changed) {
      items.filter((item) => item.groupId && expandedRef.current.has(item.groupId)).forEach((group) => {
        const groupId = group.groupId!;
        void fetchMembers(group, requestedOffsetsRef.current.get(groupId) ?? membersRef.current[groupId]?.offset ?? 0);
      });
      signatureRef.current = signature;
      refreshSequenceRef.current = refreshSequence;
    }
  }, [items, status, offset, refreshSequence, fetchMembers]);

  return { expandedGroupIds, membersByGroup, loadingGroupIds, toggleGroup, refreshOpenGroups, setGroupOffset };
}
