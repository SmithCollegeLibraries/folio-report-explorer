import type { MouseEvent } from 'react';
import { Bookmark, LayoutDashboard } from 'lucide-react';
import type { HistoryItem } from '../../types';
import { reportRunSaveState } from '../../utils/reportRunSave';

interface Props {
  item: HistoryItem;
  variant: 'icon' | 'text';
  onSave: (item: HistoryItem, pinned: boolean, event: MouseEvent<HTMLButtonElement>) => void;
}

export default function HistorySaveActions({ item, variant, onSave }: Props) {
  const eligibility = reportRunSaveState(item);
  const classes = variant === 'icon'
    ? 'inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:text-folio-600 hover:bg-folio-50 transition-colors disabled:opacity-40'
    : 'inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 text-gray-600 rounded hover:bg-gray-100 transition-colors disabled:opacity-50';

  return (
    <>
      <button type="button" disabled={!eligibility.canSave} onClick={(event) => onSave(item, false, event)}
        title={eligibility.reason ?? 'Save to Library'} aria-label={eligibility.canSave ? undefined : eligibility.reason ?? undefined} className={classes}>
        <Bookmark size={variant === 'icon' ? 13 : 14} />{variant === 'text' && ' Save'}
      </button>
      <button type="button" disabled={!eligibility.canSave} onClick={(event) => onSave(item, true, event)}
        title={eligibility.reason ?? 'Pin to Dashboard'} aria-label={eligibility.canSave ? undefined : eligibility.reason ?? undefined} className={classes}>
        <LayoutDashboard size={variant === 'icon' ? 13 : 14} />{variant === 'text' && ' Dashboard'}
      </button>
    </>
  );
}
