import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

/**
 * Auth callback page — receives JWT tokens from Shibboleth authorize.php
 * via URL fragment (#access_token=...&refresh_token=...).
 */
export default function AuthCallback() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // Parse tokens from URL fragment (hash)
    const hash = window.location.hash.substring(1); // Remove leading #
    const params = new URLSearchParams(hash);

    const accessToken = params.get('access_token');
    const refreshToken = params.get('refresh_token');

    if (accessToken && refreshToken) {
      login(accessToken, refreshToken);

      // Clean up the URL fragment
      window.history.replaceState(null, '', window.location.pathname);

      // Redirect to dashboard
      navigate('/', { replace: true });
    } else {
      setError('Authentication failed — no tokens received.');
    }
  }, [login, navigate]);

  if (error) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 max-w-md">
          <h2 className="text-lg font-semibold text-red-800 mb-2">Authentication Error</h2>
          <p className="text-red-700">{error}</p>
          <a
            href="/"
            className="inline-block mt-4 px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
          >
            Return to Home
          </a>
        </div>
      </div>
    );
  }

  return (
    <div className="flex items-center justify-center min-h-[60vh]">
      <div className="text-center">
        <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-folio-600 mx-auto mb-4" />
        <p className="text-gray-600">Completing authentication...</p>
      </div>
    </div>
  );
}
