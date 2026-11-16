import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createAcrlInvite, getAcrlInvites, resendAcrlInvite, revokeAcrlInvite } from '../api/acrl';
import type { AcrlInviteCreateInput } from '../types/acrl';
import { useAuth } from './useAuth';
import { acrlQueryKey } from '../services/acrlAccess';

export const acrlInvitesQueryKey = (year: number, userId?: number | string | null) => acrlQueryKey('acrl-invites', userId, year);

export function useAcrlInvites(year: number, enabled = true) {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const queryKey = acrlInvitesQueryKey(year, user?.id);
  const query = useQuery({
    queryKey,
    queryFn: () => getAcrlInvites(year),
    enabled: enabled && year > 0,
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey });
  const create = useMutation({
    mutationFn: (input: AcrlInviteCreateInput) => createAcrlInvite(year, input),
    onSuccess: refresh,
  });
  const resend = useMutation({
    mutationFn: (id: number) => resendAcrlInvite(id),
    onSuccess: refresh,
  });
  const revoke = useMutation({
    mutationFn: (id: number) => revokeAcrlInvite(id),
    onSuccess: refresh,
  });

  return { query, create, resend, revoke };
}
