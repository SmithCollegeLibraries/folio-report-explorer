import { useState, useCallback } from 'react';

interface UseCopyToClipboardReturn {
  /** The id of the item most recently copied, or `null` if no copy is "fresh". */
  copiedId: string | null;
  /** Copy `text` to the clipboard and record `id` as the currently-copied item.
   *  The `copiedId` resets to `null` after `resetMs` milliseconds (default 2 s). */
  copy: (id: string, text: string) => Promise<void>;
}

/**
 * Clipboard copy with per-item "Copied" feedback state.
 *
 * @param resetMs  How long (ms) the `copiedId` remains set. Defaults to 2000.
 */
export function useCopyToClipboard(resetMs = 2000): UseCopyToClipboardReturn {
  const [copiedId, setCopiedId] = useState<string | null>(null);

  const copy = useCallback(async (id: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedId(id);
      setTimeout(() => setCopiedId((prev) => (prev === id ? null : prev)), resetMs);
    } catch {
      /* clipboard access denied — fail silently */
    }
  }, [resetMs]);

  return { copiedId, copy };
}
