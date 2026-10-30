import type { HistoryItem } from '../types';

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
  void jobId;
  void parameters;
  throw new Error('not implemented');
}

export async function fetchHistoryItem(jobId: string): Promise<HistoryItem> {
  void jobId;
  throw new Error('not implemented');
}
