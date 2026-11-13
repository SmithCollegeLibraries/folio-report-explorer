import { useQuery } from '@tanstack/react-query';
import { fetchSpendingPace } from '../api/visualizations';

export function useSpendingPace(unitId: string | undefined, enabled: boolean) {
  const pace = useQuery({
    queryKey: ['visualizations', 'spending-pace', unitId],
    queryFn: () => fetchSpendingPace(unitId!),
    enabled: Boolean(unitId) && enabled,
    staleTime: 3600000,
  });
  return {
    data: pace.data,
    isPending: pace.isPending,
    error: pace.error,
    retry: () => pace.refetch(),
  };
}
