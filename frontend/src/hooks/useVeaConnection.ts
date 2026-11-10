import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  fetchVeaStatus,
  fetchVeaZones,
  saveVeaGateZones,
  testVeaConnection,
  type VeaErrorCode,
  type VeaStatus,
  type VeaTestResult,
  type VeaZone,
} from '../api/vea';

const STATUS_KEY = ['vea-status'];
const ZONES_KEY = ['vea-zones'];

function errorCode(error: unknown): VeaErrorCode {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const response = (error as { response?: { data?: { error?: unknown } } }).response;
    const code = response?.data?.error;
    if (typeof code === 'string' && [
      'not_configured', 'unreachable', 'auth_failed', 'vea_error',
      'invalid_input', 'unknown_zone', 'not_selectable', 'save_failed',
    ].includes(code)) return code as VeaErrorCode;
  }
  return 'vea_error';
}

export function useVeaConnection() {
  const queryClient = useQueryClient();
  const [zonesOpen, setZonesOpen] = useState(false);
  const [selectedZoneIds, setSelectedZoneIds] = useState<string[]>([]);
  const [testResult, setTestResult] = useState<VeaTestResult | null>(null);
  const [saveError, setSaveError] = useState<VeaErrorCode | null>(null);
  const [saved, setSaved] = useState(false);

  const statusQuery = useQuery({ queryKey: STATUS_KEY, queryFn: fetchVeaStatus, retry: false });
  const zonesQuery = useQuery({
    queryKey: ZONES_KEY,
    queryFn: fetchVeaZones,
    enabled: zonesOpen && statusQuery.data?.configured === true,
    retry: false,
  });

  useEffect(() => {
    if (statusQuery.data) setSelectedZoneIds(statusQuery.data.selectedZoneIds);
  }, [statusQuery.data]);

  const testMutation = useMutation({
    mutationFn: testVeaConnection,
    onMutate: () => setTestResult(null),
    onSuccess: setTestResult,
    onError: (error) => setTestResult({ ok: false, error: errorCode(error) }),
  });

  const saveMutation = useMutation({
    mutationFn: saveVeaGateZones,
    onMutate: () => {
      setSaveError(null);
      setSaved(false);
    },
    onSuccess: (result) => {
      setSelectedZoneIds(result.selectedZoneIds);
      setSaved(true);
      queryClient.setQueryData<VeaStatus>(STATUS_KEY, (current) => current
        ? { ...current, selectedZoneIds: result.selectedZoneIds }
        : current);
    },
    onError: (error) => {
      setSelectedZoneIds(statusQuery.data?.selectedZoneIds ?? []);
      setSaveError(errorCode(error));
    },
  });

  const toggleZone = (zoneId: string) => {
    setSelectedZoneIds((current) => current.includes(zoneId)
      ? current.filter((id) => id !== zoneId)
      : [...current, zoneId]);
    setSaveError(null);
    setSaved(false);
  };

  const save = () => {
    const selectableIds = new Set((zonesQuery.data ?? [])
      .filter((zone: VeaZone) => zone.selectable)
      .map((zone) => zone.zoneId));
    saveMutation.mutate(selectedZoneIds.filter((zoneId) => selectableIds.has(zoneId)));
  };

  return {
    status: statusQuery.data,
    statusLoading: statusQuery.isLoading,
    statusError: statusQuery.isError,
    zonesOpen,
    toggleZones: () => setZonesOpen((open) => !open),
    zones: zonesQuery.data ?? [],
    zonesLoading: zonesQuery.isLoading,
    zonesError: zonesQuery.isError ? errorCode(zonesQuery.error) : null,
    test: () => testMutation.mutate(),
    testPending: testMutation.isPending,
    testResult,
    selectedZoneIds,
    toggleZone,
    save,
    savePending: saveMutation.isPending,
    saveError,
    saved,
  };
}
