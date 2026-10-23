import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProtectedRoute from './ProtectedRoute';

const { auth } = vi.hoisted(() => ({ auth: { current: null as any } }));
vi.mock('../hooks/useAuth', () => ({
  useAuth: () => auth.current,
  getShibbolethLoginUrl: () => '/login',
}));

afterEach(cleanup);

describe('ProtectedRoute allocation access', () => {
  it('allows an allocation editor and denies an ordinary user', () => {
    auth.current = {
      user: { role: 'user' }, loading: false, authEnabled: true,
      canEditAllocationsFor: () => true,
    };
    const { rerender } = render(<ProtectedRoute allocationEditorOnly><p>Local Data</p></ProtectedRoute>);
    expect(screen.getByText('Local Data')).toBeInTheDocument();

    auth.current = {
      user: { role: 'user' }, loading: false, authEnabled: true,
      canEditAllocationsFor: () => false,
    };
    rerender(<ProtectedRoute allocationEditorOnly><p>Local Data</p></ProtectedRoute>);
    expect(screen.queryByText('Local Data')).not.toBeInTheDocument();
    expect(screen.getByText('This page is restricted to allocation editors.')).toBeInTheDocument();
  });

  it('continues to restrict admin pages to administrators', () => {
    auth.current = {
      user: { role: 'user' }, loading: false, authEnabled: true,
      canEditAllocationsFor: () => true,
    };
    render(<ProtectedRoute adminOnly><p>Users</p></ProtectedRoute>);

    expect(screen.queryByText('Users')).not.toBeInTheDocument();
    expect(screen.getByText('This page is restricted to administrators.')).toBeInTheDocument();
  });
});
