import { api } from './client';

export type VeaErrorCode =
  | 'not_configured'
  | 'unreachable'
  | 'auth_failed'
  | 'vea_error'
  | 'invalid_input'
  | 'unknown_zone'
  | 'not_selectable'
  | 'save_failed';

export interface VeaStatus {
  configured: boolean;
  selectedZoneIds: string[];
}

export interface VeaZone {
  zoneId: string;
  name: string;
  countType: number | null;
  countApplication: number | null;
  disabled: boolean;
  selectable: boolean;
}

export interface VeaTestResult {
  ok: boolean;
  zoneCount?: number;
  error?: VeaErrorCode;
}

export async function fetchVeaStatus(): Promise<VeaStatus> {
  const { data } = await api.get('/vea/status');
  return data;
}

export async function testVeaConnection(): Promise<VeaTestResult> {
  const { data } = await api.post('/vea/test');
  return data;
}

export async function fetchVeaZones(): Promise<VeaZone[]> {
  const { data } = await api.get('/vea/zones');
  return data;
}

export async function saveVeaGateZones(zoneIds: string[]): Promise<{ selectedZoneIds: string[] }> {
  const { data } = await api.put('/vea/gate-zones', { zoneIds });
  return data;
}
