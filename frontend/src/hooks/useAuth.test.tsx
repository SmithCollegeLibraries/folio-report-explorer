import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from './useAuth';
import type { AuthUser } from '../types';

function AuthSummary() {
  const { allocationColleges, canEditAllocationsFor, accessToken } = useAuth();
  return (
    <p>
      {allocationColleges.join(',')}|{String(canEditAllocationsFor())}|{String(canEditAllocationsFor('MH'))}|{String(canEditAllocationsFor('SC'))}|{accessToken}
    </p>
  );
}

function LogoutControl() {
  const { logout } = useAuth();
  return <button type="button" onClick={logout}>Log out</button>;
}

function IdentityControl() {
  const { user } = useAuth();
  return <><span>{user?.id ?? 'anonymous'}</span><button type="button" onClick={() => window.dispatchEvent(new CustomEvent('folio-auth-refresh', {
    detail: { user: authUser([], 2), accessToken: 'second-user-token' },
  }))}>Switch user</button></>;
}

function authUser(allocationColleges: string[], id = 1): AuthUser {
  return {
    id,
    smithId: 'editor1',
    username: 'editor1',
    firstName: null,
    lastName: null,
    displayName: 'editor1',
    affiliation: 'staff',
    email: null,
    role: 'user',
    isApproved: true,
    receiveNotifications: false,
    defaultCampus: 'Smith College',
    allocationColleges,
    lastLogin: null,
    createdAt: '',
  };
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe('useAuth allocation permissions', () => {
  it('loads the token assignment and adopts refreshed allocation access', async () => {
    vi.stubEnv('VITE_AUTH_ENABLED', 'true');
    const payload = btoa(JSON.stringify({
      exp: Math.floor(Date.now() / 1000) + 3600,
      user: { id: 1, username: 'editor1', firstName: null, lastName: null, role: 'user', allocationColleges: ['MH'] },
    }));
    const storage = new Map<string, string>();
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => storage.set(key, value),
      removeItem: (key: string) => storage.delete(key),
      clear: () => storage.clear(),
    });
    localStorage.setItem('fre_access_token', `header.${payload}.signature`);
    render(<QueryClientProvider client={new QueryClient()}><AuthProvider><AuthSummary /></AuthProvider></QueryClientProvider>);

    await waitFor(() => expect(screen.getByText(/MH\|true\|true\|false/)).toBeInTheDocument());
    act(() => window.dispatchEvent(new CustomEvent('folio-auth-refresh', {
      detail: { user: authUser(['SC']), accessToken: 'refreshed-token' },
    })));

    expect(screen.getByText('SC|true|false|true|refreshed-token')).toBeInTheDocument();
  });

  it('removes cached ACRL data on logout and keeps unrelated cache entries', async () => {
    const client = new QueryClient();
    vi.stubGlobal('localStorage', { removeItem: vi.fn(), getItem: vi.fn(), setItem: vi.fn() });
    client.setQueryData(['acrl-entry', '1', 'SC', '2026'], { private: true });
    client.setQueryData(['reports'], ['public']);
    render(<QueryClientProvider client={client}><AuthProvider><LogoutControl /></AuthProvider></QueryClientProvider>);

    await screen.getByRole('button', { name: 'Log out' }).click();

    expect(client.getQueryCache().findAll({ predicate: ({ queryKey }) => String(queryKey[0]).startsWith('acrl') })).toHaveLength(0);
    expect(client.getQueryData(['reports'])).toEqual(['public']);
  });

  it('drops the previous user ACRL cache when identity changes outside the entry page', async () => {
    const client = new QueryClient();
    vi.stubGlobal('localStorage', { removeItem: vi.fn(), getItem: vi.fn(), setItem: vi.fn() });
    render(<QueryClientProvider client={client}><AuthProvider><IdentityControl /></AuthProvider></QueryClientProvider>);
    act(() => window.dispatchEvent(new CustomEvent('folio-auth-refresh', {
      detail: { user: authUser([]), accessToken: 'first-user-token' },
    })));
    await waitFor(() => expect(screen.getByText('1')).toBeInTheDocument());
    client.setQueryData(['acrl-entry', '1', 'SC', '2026'], { private: true });

    await screen.getByRole('button', { name: 'Switch user' }).click();

    await waitFor(() => expect(screen.getByText('2')).toBeInTheDocument());
    expect(client.getQueryData(['acrl-entry', '1', 'SC', '2026'])).toBeUndefined();
  });
});
