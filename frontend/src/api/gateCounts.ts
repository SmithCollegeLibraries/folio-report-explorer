import { api } from './client';
import type { GateCountResponse } from '../types/gateCounts';

export interface GateCountFilters {
  start?: string;
  end?: string;
  years?: number;
}

export async function fetchGateCounts(filters: GateCountFilters = {}): Promise<GateCountResponse> {
  const { data } = await api.get('/visualizations/gate-counts', { params: filters });
  return data;
}
