import { useState, useCallback } from 'react';
import { renameHistoryJob } from '../api/client';

export interface UseInlineRenameOptions {
  /** Called after a successful rename with the job ID and the new name (empty string means cleared). */
  onCommit: (jobId: string, newName: string) => void;
  /** Called when the API call fails. */
  onError: (message: string) => void;
}

export interface UseInlineRenameReturn {
  /** ID of the row currently being renamed, or `null`. */
  renamingId: string | null;
  /** Current value of the rename input. */
  renameValue: string;
  setRenameValue: React.Dispatch<React.SetStateAction<string>>;
  /** True while the API call is in-flight. */
  renameSaving: boolean;
  /** Start renaming `jobId`, pre-filling `currentName`. */
  start: (jobId: string, currentName: string | null, e?: React.MouseEvent) => void;
  /** Cancel rename without saving. */
  cancel: () => void;
  /** Persist the rename for `jobId`. No-ops if the value hasn't changed. */
  commit: (jobId: string, originalName: string | null) => Promise<void>;
}

/**
 * Manages in-place row rename state and the API call.
 * The parent component is notified via `onCommit` / `onError` callbacks
 * so it can update its own item list.
 */
export function useInlineRename({ onCommit, onError }: UseInlineRenameOptions): UseInlineRenameReturn {
  const [renamingId, setRenamingId] = useState<string | null>(null);
  const [renameValue, setRenameValue] = useState('');
  const [renameSaving, setRenameSaving] = useState(false);

  const start = useCallback((jobId: string, currentName: string | null, e?: React.MouseEvent) => {
    e?.stopPropagation();
    setRenamingId(jobId);
    setRenameValue(currentName ?? '');
  }, []);

  const cancel = useCallback(() => {
    setRenamingId(null);
    setRenameValue('');
  }, []);

  const commit = useCallback(async (jobId: string, originalName: string | null) => {
    const val = renameValue.trim();
    if (val === (originalName ?? '')) { cancel(); return; }
    setRenameSaving(true);
    try {
      await renameHistoryJob(jobId, val);
      onCommit(jobId, val);
    } catch (e: any) {
      onError(e.response?.data?.error || 'Rename failed');
    } finally {
      setRenameSaving(false);
      cancel();
    }
  }, [renameValue, cancel, onCommit, onError]);

  return { renamingId, renameValue, setRenameValue, renameSaving, start, cancel, commit };
}
