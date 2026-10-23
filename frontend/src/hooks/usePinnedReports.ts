import { useCallback, useEffect, useState } from 'react';

const PINNED_REPORTS_STORAGE_KEY = 'folio-report-explorer:pinned-reports';

function readPinnedReportIds(): number[] {
  try {
    const saved = window.localStorage.getItem(PINNED_REPORTS_STORAGE_KEY);
    if (!saved) return [];

    const parsed: unknown = JSON.parse(saved);
    if (!Array.isArray(parsed)) return [];

    return Array.from(new Set(parsed.filter(
      (id): id is number => typeof id === 'number' && Number.isSafeInteger(id) && id > 0,
    )));
  } catch {
    return [];
  }
}

function writePinnedReportIds(ids: number[]) {
  try {
    window.localStorage.setItem(PINNED_REPORTS_STORAGE_KEY, JSON.stringify(ids));
  } catch {
    // Keep the current page usable when browser storage is disabled.
  }
}

export function usePinnedReports() {
  const [pinnedIds, setPinnedIds] = useState<number[]>(readPinnedReportIds);

  useEffect(() => {
    const refreshFromStorage = (event: StorageEvent) => {
      if (event.key && event.key !== PINNED_REPORTS_STORAGE_KEY) return;
      setPinnedIds(readPinnedReportIds());
    };

    window.addEventListener('storage', refreshFromStorage);
    return () => window.removeEventListener('storage', refreshFromStorage);
  }, []);

  const togglePinned = useCallback((reportId: number) => {
    setPinnedIds((current) => {
      const next = current.includes(reportId)
        ? current.filter((id) => id !== reportId)
        : [reportId, ...current];
      writePinnedReportIds(next);
      return next;
    });
  }, []);

  return { pinnedIds, togglePinned };
}
