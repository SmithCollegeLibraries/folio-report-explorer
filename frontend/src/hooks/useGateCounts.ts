import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchGateCounts } from '../api/gateCounts';
import type { GateCountFilters } from '../api/gateCounts';

type GateCountPeriod = { start: string; end: string };

export function useGateCounts() {
  const [period, setPeriod] = useState<GateCountPeriod>();
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [years, setYears] = useState(3);
  const query = useQuery({
    queryKey: ['gate-counts', period?.start, period?.end, years],
    queryFn: () => fetchGateCounts({
      years,
      ...(period ? { start: period.start, end: period.end } : {}),
    } satisfies GateCountFilters),
    retry: false,
  });

  useEffect(() => {
    if (query.data && !start && !end) {
      setStart(query.data.period.start);
      setEnd(query.data.period.end);
    }
  }, [query.data, start, end]);

  const changeStart = (value: string) => {
    setStart(value);
    const resolvedEnd = end || query.data?.period.end || '';
    if (resolvedEnd) setPeriod({ start: value, end: resolvedEnd });
  };
  const changeEnd = (value: string) => {
    setEnd(value);
    const resolvedStart = start || query.data?.period.start || '';
    if (resolvedStart) setPeriod({ start: resolvedStart, end: value });
  };

  return {
    query,
    data: query.data,
    start: start || query.data?.period.start || '',
    end: end || query.data?.period.end || '',
    years,
    changeStart,
    changeEnd,
    setYears,
    retry: () => query.refetch(),
  };
}
