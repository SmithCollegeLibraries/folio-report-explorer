import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createAcrlInvite, getAcrlInvites, resendAcrlInvite, revokeAcrlInvite } from '../api/acrl';
import type { AcrlInviteCreateInput } from '../types/acrl';

export const acrlInvitesQueryKey = (year: number) => ['acrl-invites', year] as const;

export function useAcrlInvites(year: number, enabled = true) {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: acrlInvitesQueryKey(year),
    queryFn: () => getAcrlInvites(year),
    enabled: enabled && year > 0,
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: acrlInvitesQueryKey(year) });
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
