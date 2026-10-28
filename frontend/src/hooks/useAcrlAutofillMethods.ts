import { useQuery } from '@tanstack/react-query';
import { getAcrlAutofillMethods } from '../api/acrl';
import type { AcrlAutofillMethod, AcrlSheet } from '../types/acrl';

export const acrlAutofillMethodsQueryKey = ['acrl-autofill-methods'] as const;

export default function useAcrlAutofillMethods(sheet: AcrlSheet, questionId: string, part: string, enabled = true) {
  const query = useQuery({
    queryKey: acrlAutofillMethodsQueryKey,
    queryFn: getAcrlAutofillMethods,
    staleTime: Infinity,
    gcTime: Infinity,
    retry: false,
    enabled,
  });
  const method = query.data?.methods.find((item: AcrlAutofillMethod) => (
    item.sheet === sheet && item.questionId === questionId && item.part === part
  )) ?? null;

  return { ...query, method };
}
