import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { promoteToReport } from '../api/client';

type PromotionResult = Awaited<ReturnType<typeof promoteToReport>>;

export function useSavedQueryPromotion() {
  const [promotions, setPromotions] = useState<Record<number, PromotionResult>>({});
  const mutation = useMutation({
    mutationFn: (savedQueryId: number) => promoteToReport(savedQueryId),
    onSuccess: (result, savedQueryId) => {
      setPromotions((current) => ({ ...current, [savedQueryId]: result }));
    },
  });

  return {
    promote: (savedQueryId: number) => mutation.mutate(savedQueryId),
    isPending: (savedQueryId: number) => mutation.isPending && mutation.variables === savedQueryId,
    promotionFor: (savedQueryId: number) => promotions[savedQueryId],
  };
}
