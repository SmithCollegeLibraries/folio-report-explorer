import { api } from './client';
import type { HistoryItem, HistoryResponse, HistorySuggestionsResponse } from '../types';

export interface HistoryGroupListItem extends HistoryItem {
  groupId: string | null;
  runCount: number;
  activeRunCount: number;
}

export interface HistoryGroupResponse {
  total: number;
  offset: number;
  limit: number;
  items: HistoryItem[];
}

export interface HistoryGroupParameters {
  status?: string;
  limit?: number;
  offset?: number;
}

export async function fetchHistoryGroup(
  jobId: string,
  parameters: HistoryGroupParameters,
): Promise<HistoryGroupResponse> {
  const params: Record<string, string | number> = {
    limit: Math.max(1, Math.min(100, Math.trunc(parameters.limit ?? 20))),
    offset: Math.max(0, Math.trunc(parameters.offset ?? 0)),
  };
  if (parameters.status && parameters.status !== 'all') params.status = parameters.status;
  const { data } = await api.get(`/query/history/${encodeURIComponent(jobId)}/group`, { params });
  return data;
}

export async function fetchHistoryItem(jobId: string): Promise<HistoryItem> {
  const { data } = await api.get(`/query/history/${encodeURIComponent(jobId)}`);
  return data;
}

export async function fetchQueryHistory(
  limit = 50,
  offset = 0,
  status?: string,
  mine = false,
): Promise<HistoryResponse> {
  const params: Record<string, string | number> = { limit, offset };
  if (status && status !== 'all') params.status = status;
  if (mine) params.mine = 1;
  const { data } = await api.get('/query/history', { params });
  return data;
}

export async function fetchHistorySuggestions(jobId: string): Promise<HistorySuggestionsResponse> {
  const { data } = await api.post(`/query/history/${encodeURIComponent(jobId)}/suggestions`);
  return data;
}

export async function renameHistoryJob(
  jobId: string,
  name: string,
): Promise<{ jobId: string; name: string | null }> {
  const { data } = await api.patch(`/query/history/${encodeURIComponent(jobId)}`, { name });
  return data;
}

export async function deleteHistoryJob(jobId: string): Promise<void> {
  await api.delete(`/query/history/${encodeURIComponent(jobId)}`);
}
