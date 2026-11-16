import { useQuery } from '@tanstack/react-query';
import { getAcrlAutofillMethods } from '../api/acrl';
import type { AcrlAutofillMethod, AcrlSheet } from '../types/acrl';
import { useAuth } from './useAuth';
import { acrlQueryKey } from '../services/acrlAccess';

export default function useAcrlAutofillMethods(sheet: AcrlSheet, questionId: string, part: string, enabled = true) {
  const { user } = useAuth();
  const query = useQuery({
    queryKey: acrlQueryKey('acrl-autofill-methods', user?.id),
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
