import type { QueryClient } from '@tanstack/react-query';

export const ACRL_INSTITUTION = 'SC';

export function acrlIdentity(userId: number | string | null | undefined): string {
  return userId === null || userId === undefined ? 'anonymous' : String(userId);
}

export function acrlQueryKey(resource: string, userId: number | string | null | undefined, ...parts: unknown[]) {
  return [resource, acrlIdentity(userId), ACRL_INSTITUTION, ...parts] as const;
}

export function isAcrlQueryKey(key: readonly unknown[]): boolean {
  return typeof key[0] === 'string' && key[0].startsWith('acrl');
}

export function clearAcrlQueries(client: QueryClient, identity?: string, preserve?: readonly unknown[]): void {
  client.removeQueries({ predicate: ({ queryKey }) => isAcrlQueryKey(queryKey)
    && (identity === undefined || queryKey[1] === identity)
    && (!preserve || queryKey.length !== preserve.length || queryKey.some((part, index) => part !== preserve[index])) });
}

export function acrlAccessError(error: unknown): { status: number; reason?: string } | null {
  if (!error || typeof error !== 'object' || !('response' in error)) return null;
  const response = (error as { response?: { status?: number; data?: { reason?: unknown } } }).response;
  if (response?.status !== 401 && response?.status !== 403) return null;
  return {
    status: response.status,
    reason: typeof response.data?.reason === 'string' ? response.data.reason : undefined,
  };
}
