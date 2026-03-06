import { useState, useEffect, useCallback } from 'react';
import { fetchQueryHistory } from '../api/client';
import type { HistoryItem } from '../types';

const PAGE_LIMIT = 50;

export interface UseHistoryDataReturn {
  items: HistoryItem[];
  setItems: React.Dispatch<React.SetStateAction<HistoryItem[]>>;
  total: number;
  setTotal: React.Dispatch<React.SetStateAction<number>>;
  offset: number;
  setOffset: React.Dispatch<React.SetStateAction<number>>;
  loading: boolean;
  error: string | null;
  setError: React.Dispatch<React.SetStateAction<string | null>>;
  statusTab: string;
  handleTabChange: (tab: string) => void;
  mineOnly: boolean;
  handleMineOnlyChange: (mineOnly: boolean) => void;
  hasActive: boolean;
  load: () => Promise<void>;
  limit: number;
  totalPages: number;
  currentPage: number;
  expandedErrors: Set<string>;
  toggleExpandError: (jobId: string) => void;
}

/**
 * Manages history data fetching, pagination, tab state, and auto-refresh.
 * Auto-refreshes every 5 s whenever the current page has pending/running jobs.
 */
export function useHistoryData(): UseHistoryDataReturn {
  const [items, setItems] = useState<HistoryItem[]>([]);
  const [total, setTotal] = useState(0);
  const [offset, setOffset] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusTab, setStatusTab] = useState<string>('all');
  const [mineOnly, setMineOnly] = useState(false);
  const [expandedErrors, setExpandedErrors] = useState<Set<string>>(new Set());

  const load = useCallback(async () => {
    try {
      setLoading(true);
      const data = await fetchQueryHistory(PAGE_LIMIT, offset, statusTab, mineOnly);
      setItems(data.items);
      setTotal(data.total);
      setError(null);
    } catch (e: any) {
      setError(e.response?.data?.error || 'Failed to load history');
    } finally {
      setLoading(false);
    }
  }, [offset, statusTab, mineOnly]);

  useEffect(() => { load(); }, [load]);

  const hasActive = items.some((i) => i.status === 'pending' || i.status === 'pending_export' || i.status === 'running');

  // Auto-refresh while there are active jobs on this page
  useEffect(() => {
    if (!hasActive) return;
    const timer = setInterval(() => { load(); }, 5000);
    return () => clearInterval(timer);
  }, [hasActive, load]);

  const handleTabChange = useCallback((tab: string) => {
    setStatusTab(tab);
    setOffset(0);
    if (tab !== 'failed') setExpandedErrors(new Set());
  }, []);

  const handleMineOnlyChange = useCallback((next: boolean) => {
    setMineOnly(next);
    setOffset(0);
  }, []);

  // Auto-expand error rows when the Failed tab loads
  useEffect(() => {
    if (statusTab === 'failed') {
      setExpandedErrors(new Set(items.filter((i) => i.errorMessage).map((i) => i.jobId)));
    }
  }, [statusTab, items]);

  const toggleExpandError = useCallback((jobId: string) => {
    setExpandedErrors((prev) => {
      const next = new Set(prev);
      if (next.has(jobId)) next.delete(jobId); else next.add(jobId);
      return next;
    });
  }, []);

  const totalPages = Math.ceil(total / PAGE_LIMIT);
  const currentPage = Math.floor(offset / PAGE_LIMIT) + 1;

  return {
    items, setItems,
    total, setTotal,
    offset, setOffset,
    loading,
    error, setError,
    statusTab, handleTabChange,
    mineOnly, handleMineOnlyChange,
    hasActive,
    load,
    limit: PAGE_LIMIT,
    totalPages,
    currentPage,
    expandedErrors, toggleExpandError,
  };
}
