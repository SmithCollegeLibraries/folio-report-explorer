import { useEffect } from 'react';

/**
 * Registers an `Escape` keydown listener and calls `callback` when triggered.
 *
 * @param callback  Function to invoke on Escape. Use a stable reference
 *                  (e.g. `useCallback`) to avoid unnecessary re-registration.
 * @param enabled   When `false` the listener is not registered. Defaults to `true`.
 */
export function useEscapeKey(callback: () => void, enabled = true): void {
  useEffect(() => {
    if (!enabled) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') callback();
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [callback, enabled]);
}
