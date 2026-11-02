import type { ReactElement, ReactNode } from 'react';
import { Activity, ChevronDown, ChevronRight, Loader2 } from 'lucide-react';
import type { HistoryItem } from '../../types';
import type { HistoryGroupListItem } from '../../api/history';
import type { HistoryGroupResponse } from '../../api/history';

export interface HistoryGroupRowsProps {
  group: HistoryGroupListItem;
  members: HistoryItem[];
  expanded: boolean;
  loading: boolean;
  onToggle: (group: HistoryGroupListItem) => void;
  onOpen: (member: HistoryItem) => void;
  onDelete: (jobId: string) => void;
  onCancel: (jobId: string) => void;
  onRename: (member: HistoryItem) => void;
  onConfirmDelete?: (jobId: string) => void;
  onCancelDelete?: () => void;
  confirmDeleteId?: string | null;
  deletingId?: string | null;
  renamingId?: string | null;
  renameValue?: string;
  onRenameValueChange?: (value: string) => void;
  renameSaving?: boolean;
  onCommitRename?: (jobId: string, originalName: string | null) => void;
  onCancelRename?: () => void;
  page?: HistoryGroupResponse;
  onPageChange?: (offset: number) => void;
  columnCount?: number;
  renderRepresentative?: (toggle: ReactElement) => ReactNode;
}

const activeStatuses = new Set(['pending', 'pending_export', 'running', 'cancelling']);

export default function HistoryGroupRows({
  group, members, expanded, loading, onToggle, onOpen, onDelete, onCancel, onRename,
  onConfirmDelete, onCancelDelete, confirmDeleteId, deletingId,
  renamingId, renameValue, onRenameValueChange, renameSaving, onCommitRename, onCancelRename,
  page, onPageChange, columnCount = 1, renderRepresentative,
}: HistoryGroupRowsProps) {
  const active = (group.activeRunCount ?? 0) > 0;
  const toggle = (
    <button
      type="button"
      aria-expanded={expanded}
      aria-label={`Run ${group.runCount ?? 1} times`}
      onClick={(event) => { event.stopPropagation(); onToggle(group); }}
      className="inline-flex items-center gap-1 rounded border border-folio-200 px-2 py-0.5 text-xs font-medium text-folio-700 hover:bg-folio-50"
    >
      {expanded ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
      Run {group.runCount ?? 1} times
      {active && <span className="ml-1 inline-flex items-center gap-1 text-blue-700"><Activity size={11} /> Running</span>}
    </button>
  );

  return (
    <>
      {renderRepresentative ? renderRepresentative(toggle) : (
        <tr className="bg-white">
          <td colSpan={columnCount} className="px-4 py-2 border-b border-gray-100">
            <div className="flex items-center gap-2">{toggle}{loading && <Loader2 size={13} className="animate-spin" />}</div>
          </td>
        </tr>
      )}
      {expanded && (loading && members.length === 0 ? (
        <tr><td colSpan={columnCount} className="px-4 py-3 text-center text-xs text-gray-400"><Loader2 size={14} className="inline animate-spin" /> Loading runs…</td></tr>
      ) : members.filter((member) => member.jobId !== group.jobId).map((member) => (
        <tr key={`member-${member.jobId}`} className="bg-folio-50/40 border-b border-gray-100">
          <td colSpan={columnCount} className="px-4 py-2 pl-10 text-xs text-gray-600">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
              {renamingId === member.jobId ? (
                <form onSubmit={(event) => { event.preventDefault(); onCommitRename?.(member.jobId, member.name); }}>
                  <input autoFocus value={renameValue ?? ''} onChange={(event) => onRenameValueChange?.(event.target.value)}
                    onBlur={() => onCommitRename?.(member.jobId, member.name)} onKeyDown={(event) => { if (event.key === 'Escape') onCancelRename?.(); }}
                    disabled={renameSaving} className="w-40 rounded border px-2 py-1" />
                </form>
              ) : <span className="font-medium text-gray-700">{member.name || 'Unnamed query'}</span>}
              <time>{member.createdAt ? new Date(member.createdAt).toLocaleString() : 'Unknown time'}</time>
              <span className="capitalize">{member.status.replace('_', ' ')}</span>
              <span>{member.rowCount.toLocaleString()} rows</span>
              {member.status === 'completed' && <button type="button" onClick={() => onOpen(member)} className="text-folio-700 hover:underline">Open</button>}
              {member.canDelete && (confirmDeleteId === member.jobId ? <span className="inline-flex gap-1">
                <button type="button" disabled={deletingId === member.jobId} onClick={() => onDelete(member.jobId)}>Yes</button>
                <button type="button" onClick={onCancelDelete}>No</button>
              </span> : <button type="button" onClick={() => onConfirmDelete ? onConfirmDelete(member.jobId) : onDelete(member.jobId)} className="text-red-600 hover:underline">Delete</button>)}
              {member.status === 'completed' && <button type="button" onClick={() => onRename(member)} className="text-gray-600 hover:underline">Rename</button>}
              {activeStatuses.has(member.status) && member.status !== 'cancelling' && (
                <button type="button" onClick={() => onCancel(member.jobId)} className="text-amber-700 hover:underline">Cancel</button>
              )}
            </div>
          </td>
        </tr>
      )))}
      {expanded && page && page.total > page.limit && (
        <tr><td colSpan={columnCount} className="px-4 py-2 pl-10 text-xs text-gray-500">
          <button type="button" disabled={page.offset === 0} onClick={() => onPageChange?.(page.offset - page.limit)}>Previous runs</button>
          <span className="mx-2">{page.offset + 1}–{Math.min(page.offset + page.limit, page.total)} of {page.total}</span>
          <button type="button" disabled={page.offset + page.limit >= page.total} onClick={() => onPageChange?.(page.offset + page.limit)}>Next runs</button>
        </td></tr>
      )}
    </>
  );
}
