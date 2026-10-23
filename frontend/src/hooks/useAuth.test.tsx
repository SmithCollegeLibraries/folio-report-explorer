import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
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

function authUser(allocationColleges: string[]): AuthUser {
  return {
    id: 1,
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
    render(<AuthProvider><AuthSummary /></AuthProvider>);

    await waitFor(() => expect(screen.getByText(/MH\|true\|true\|false/)).toBeInTheDocument());
    act(() => window.dispatchEvent(new CustomEvent('folio-auth-refresh', {
      detail: { user: authUser(['SC']), accessToken: 'refreshed-token' },
    })));

    expect(screen.getByText('SC|true|false|true|refreshed-token')).toBeInTheDocument();
  });
});
