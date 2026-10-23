import axios, { type AxiosInstance } from 'axios';
import type { AuthUser, RefreshResponse } from '../types';
import { getStoredAccessToken, getStoredRefreshToken } from '../hooks/useAuth';

export function configureAuthInterceptors(client: AxiosInstance, apiBase: string, basePath: string): void {
  client.interceptors.request.use((config) => {
    const token = getStoredAccessToken();
    if (token) {
      config.headers = config.headers || {};
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  });

  let isRefreshing = false;
  let refreshSubscribers: ((token: string) => void)[] = [];

  const onRefreshed = (token: string) => {
    refreshSubscribers.forEach((callback) => callback(token));
    refreshSubscribers = [];
  };

  client.interceptors.response.use(
    (response) => response,
    async (error) => {
      const originalRequest = error.config;
      const authEnabled = import.meta.env.VITE_AUTH_ENABLED === 'true';
      if (error.response?.status !== 401 || !authEnabled) return Promise.reject(error);
      if (originalRequest.url?.includes('/auth/refresh') || originalRequest._retry) {
        window.location.href = `${basePath}/admin/authorize.php`;
        return Promise.reject(error);
      }

      if (!isRefreshing) {
        isRefreshing = true;
        const refreshToken = getStoredRefreshToken();
        if (!refreshToken) {
          window.location.href = `${basePath}/admin/authorize.php`;
          return Promise.reject(error);
        }

        try {
          const { data } = await axios.post<RefreshResponse>(`${apiBase}/auth/refresh`, { refreshToken });
          localStorage.setItem('fre_access_token', data.accessToken);
          localStorage.setItem('fre_refresh_token', data.refreshToken);
          window.dispatchEvent(new CustomEvent<{ user: AuthUser; accessToken: string }>('folio-auth-refresh', {
            detail: { user: data.user, accessToken: data.accessToken },
          }));
          isRefreshing = false;
          onRefreshed(data.accessToken);
        } catch {
          isRefreshing = false;
          localStorage.removeItem('fre_access_token');
          localStorage.removeItem('fre_refresh_token');
          window.location.href = `${basePath}/admin/authorize.php`;
          return Promise.reject(error);
        }
      }

      return new Promise((resolve) => {
        refreshSubscribers.push((token: string) => {
          originalRequest._retry = true;
          originalRequest.headers.Authorization = `Bearer ${token}`;
          resolve(client(originalRequest));
        });
      });
    },
  );
}
