import type { ReactNode } from 'react';
import { useAuth, getShibbolethLoginUrl } from '../hooks/useAuth';

interface ProtectedRouteProps {
  children: ReactNode;
  /** If true, only allow admin users */
  adminOnly?: boolean;
}

/**
 * Wraps a route component to require authentication.
 * - When auth is disabled (dev mode), renders children directly.
 * - When auth is enabled but user is not logged in, redirects to Shibboleth.
 * - When adminOnly is set, non-admin users see a 403 message.
 */
export default function ProtectedRoute({ children, adminOnly = false }: ProtectedRouteProps) {
  const { user, loading, authEnabled } = useAuth();

  // In dev mode, skip auth entirely
  if (!authEnabled) {
    return <>{children}</>;
  }

  // Still loading auth state
  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-folio-600" />
      </div>
    );
  }

  // Not authenticated → redirect to Shibboleth
  if (!user) {
    window.location.href = getShibbolethLoginUrl();
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <p className="text-gray-500">Redirecting to login...</p>
      </div>
    );
  }

  // Admin check
  if (adminOnly && user.role !== 'admin') {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 max-w-md text-center">
          <h2 className="text-lg font-semibold text-red-800 mb-2">Access Denied</h2>
          <p className="text-red-700">
            This page is restricted to administrators.
          </p>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
