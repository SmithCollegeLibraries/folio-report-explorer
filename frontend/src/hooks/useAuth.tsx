import { createContext, useContext, useState, useCallback, useEffect, type ReactNode } from 'react';
import type { AuthUser, JwtPayload } from '../types';

interface AuthContextType {
  /** Current authenticated user (null if not logged in) */
  user: AuthUser | null;
  /** Raw JWT access token */
  accessToken: string | null;
  /** Whether auth is still being initialized (checking localStorage) */
  loading: boolean;
  /** Whether the user is an admin */
  isAdmin: boolean;
  /** Whether auth is enabled (production mode) */
  authEnabled: boolean;
  /** Store tokens from Shibboleth callback */
  login: (accessToken: string, refreshToken: string) => void;
  /** Clear tokens and logout */
  logout: () => void;
}

const AuthContext = createContext<AuthContextType>({
  user: null,
  accessToken: null,
  loading: true,
  isAdmin: false,
  authEnabled: false,
  login: () => {},
  logout: () => {},
});

/**
 * Decode a JWT payload without verification (client-side only).
 * Verification happens server-side.
 */
function decodeJwt(token: string): JwtPayload | null {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    const payload = JSON.parse(atob(parts[1]));
    return payload as JwtPayload;
  } catch {
    return null;
  }
}

/**
 * Check if a JWT token is expired (with 60s buffer).
 */
function isTokenExpired(token: string): boolean {
  const payload = decodeJwt(token);
  if (!payload?.exp) return true;
  return payload.exp * 1000 < Date.now() - 60000;
}

const STORAGE_ACCESS_KEY = 'fre_access_token';
const STORAGE_REFRESH_KEY = 'fre_refresh_token';

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [accessToken, setAccessToken] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  // Auth is enabled in production (when VITE_AUTH_ENABLED is set)
  // In dev mode (Docker), auth is disabled by default
  const authEnabled = import.meta.env.VITE_AUTH_ENABLED === 'true';

  const login = useCallback((newAccessToken: string, newRefreshToken: string) => {
    localStorage.setItem(STORAGE_ACCESS_KEY, newAccessToken);
    localStorage.setItem(STORAGE_REFRESH_KEY, newRefreshToken);
    setAccessToken(newAccessToken);

    const payload = decodeJwt(newAccessToken);
    if (payload?.user) {
      setUser({
        id: payload.user.id,
        smithId: '',
        username: payload.user.username,
        firstName: payload.user.firstName,
        lastName: payload.user.lastName,
        displayName: payload.user.firstName && payload.user.lastName
          ? `${payload.user.firstName} ${payload.user.lastName}`
          : payload.user.username,
        affiliation: null,
        email: null,
        role: payload.user.role,
        isApproved: true,
        receiveNotifications: false,
        lastLogin: null,
        createdAt: '',
      });
    }
  }, []);

  const logout = useCallback(() => {
    localStorage.removeItem(STORAGE_ACCESS_KEY);
    localStorage.removeItem(STORAGE_REFRESH_KEY);
    setAccessToken(null);
    setUser(null);
  }, []);

  // Initialize from localStorage on mount
  useEffect(() => {
    if (!authEnabled) {
      setLoading(false);
      return;
    }

    const stored = localStorage.getItem(STORAGE_ACCESS_KEY);
    if (stored && !isTokenExpired(stored)) {
      const payload = decodeJwt(stored);
      if (payload?.user) {
        setAccessToken(stored);
        setUser({
          id: payload.user.id,
          smithId: '',
          username: payload.user.username,
          firstName: payload.user.firstName,
          lastName: payload.user.lastName,
          displayName: payload.user.firstName && payload.user.lastName
            ? `${payload.user.firstName} ${payload.user.lastName}`
            : payload.user.username,
          affiliation: null,
          email: null,
          role: payload.user.role,
          isApproved: true,
          receiveNotifications: false,
          lastLogin: null,
          createdAt: '',
        });
      }
    }
    setLoading(false);
  }, [authEnabled]);

  const isAdmin = user?.role === 'admin';

  return (
    <AuthContext.Provider value={{ user, accessToken, loading, isAdmin, authEnabled, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}

/**
 * Get the Shibboleth login URL for the current deployment.
 */
export function getShibbolethLoginUrl(): string {
  const basePath = (import.meta.env.VITE_BASE_PATH || '').replace(/\/$/, '');
  return `${basePath}/admin/authorize.php`;
}

/**
 * Get the stored refresh token.
 */
export function getStoredRefreshToken(): string | null {
  return localStorage.getItem(STORAGE_REFRESH_KEY);
}

/**
 * Get the stored access token.
 */
export function getStoredAccessToken(): string | null {
  return localStorage.getItem(STORAGE_ACCESS_KEY);
}
