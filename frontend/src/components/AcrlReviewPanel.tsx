import { useMemo, useState } from 'react';
import type { AcrlLinkDecision, AcrlLinkDecisionInput, AcrlQuestionPart, AcrlReviewItem, AcrlReviewResponse, AcrlReviewStatus } from '../types/acrl';
import AcrlPromptComparison from './AcrlPromptComparison';

const GROUPS: Array<{ status: AcrlReviewStatus; title: string }> = [
  { status: 'new', title: 'New' },
  { status: 'removed', title: 'Removed' },
  { status: 'reworded', title: 'Reworded' },
  { status: 'renumbered', title: 'Renumbered' },
  { status: 'unchanged', title: 'Unchanged' },
];

function questionKey(question: AcrlQuestionPart): string {
  return `${question.sheet}|${question.question_id}|${question.part}`;
}

function reviewItemKey(item: AcrlReviewItem): string {
  return `${item.previous ? questionKey(item.previous) : 'none'}→${item.current ? questionKey(item.current) : 'none'}`;
}

function isBulkReviewItem(item: AcrlReviewItem): boolean {
  return (item.status === 'reworded' || item.status === 'renumbered')
    && item.score >= 0.5
    && !item.decision
    && item.previous !== null
    && item.current !== null;
}

function linkInput(item: AcrlReviewItem, decision: AcrlLinkDecision): AcrlLinkDecisionInput | null {
  if (!item.previous || !item.current) return null;
  return {
    sheet: item.current.sheet,
    previous_question_id: item.previous.question_id,
    previous_part: item.previous.part,
    current_question_id: item.current.question_id,
    current_part: item.current.part,
    decision,
  };
}

export default function AcrlReviewPanel({
  review, saving, publishing, onSave, onPublish,
}: {
  review: AcrlReviewResponse;
  saving: boolean;
  publishing: boolean;
  onSave: (links: AcrlLinkDecisionInput[]) => void;
  onPublish: () => void;
}) {
  const [manualPrevious, setManualPrevious] = useState<Record<string, string>>({});
  const [selectedKeys, setSelectedKeys] = useState<string[]>([]);
  const groupedItems = useMemo(() => GROUPS.map(({ status, title }) => ({
    status,
    title,
    items: review.items.filter((item) => item.status === status),
  })), [review.items]);
  const bulkLinks = review.items
    .filter((item) => item.status === 'unchanged' && item.score >= 0.85 && !item.decision)
    .map((item) => linkInput(item, 'approved'))
    .filter((link): link is AcrlLinkDecisionInput => link !== null);
  const selectedLinks = review.items
    .filter((item) => isBulkReviewItem(item) && selectedKeys.includes(reviewItemKey(item)))
    .map((item) => linkInput(item, 'approved'))
    .filter((link): link is AcrlLinkDecisionInput => link !== null);

  const toggleSelection = (item: AcrlReviewItem) => {
    const key = reviewItemKey(item);
    setSelectedKeys((current) => current.includes(key)
      ? current.filter((selectedKey) => selectedKey !== key)
      : [...current, key]);
  };

  const toggleGroupSelection = (items: AcrlReviewItem[]) => {
    const keys = items.filter(isBulkReviewItem).map(reviewItemKey);
    const allSelected = keys.length > 0 && keys.every((key) => selectedKeys.includes(key));
    setSelectedKeys((current) => {
      const next = new Set(current);
      keys.forEach((key) => allSelected ? next.delete(key) : next.add(key));
      return Array.from(next);
    });
  };

  const approveSelected = () => {
    if (selectedLinks.length) onSave(selectedLinks);
  };

  const saveDecision = (item: AcrlReviewItem, decision: AcrlLinkDecision) => {
    const link = linkInput(item, decision);
    if (link) onSave([link]);
  };

  const renderItem = (item: AcrlReviewItem, index: number) => {
    const currentKey = item.current ? questionKey(item.current) : '';
    const pickedPreviousKey = manualPrevious[currentKey] || '';
    const statusLabel = item.status[0].toUpperCase() + item.status.slice(1);
    return (
      <article key={`${item.previous?.id || 'none'}-${item.current?.id || 'none'}-${index}`} className="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex flex-wrap items-center gap-2">
          <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{statusLabel}</span>
          {item.topical && <span className="rounded-full bg-violet-100 px-2.5 py-1 text-xs font-medium text-violet-800">Topical · no change rate</span>}
          {item.matchKind && <span className="text-xs text-gray-500">{item.matchKind} · {Math.round(item.score * 100)}% text match</span>}
          {item.decision && <span className={`text-xs font-semibold ${item.decision === 'approved' ? 'text-emerald-700' : 'text-rose-700'}`}>{item.decision}</span>}
        </div>
        <AcrlPromptComparison previous={item.previous} current={item.current} />
        {item.previous && item.current && (
          <div className="flex flex-wrap items-center gap-2">
            {(item.status === 'reworded' || item.status === 'renumbered') && (
              <label className="flex items-center gap-2 rounded border border-gray-300 px-2 py-1.5 text-sm text-gray-700">
                <input
                  type="checkbox"
                  aria-label={`Select ${item.current.question_id}${item.current.part} ${item.status} match`}
                  checked={isBulkReviewItem(item) && selectedKeys.includes(reviewItemKey(item))}
                  disabled={saving || !isBulkReviewItem(item)}
                  onChange={() => toggleSelection(item)}
                />
                Select
              </label>
            )}
            <button className="rounded border border-emerald-700 px-3 py-1.5 text-sm font-medium text-emerald-800 hover:bg-emerald-50 disabled:opacity-50" disabled={saving || item.decision === 'approved'} onClick={() => saveDecision(item, 'approved')}>Approve</button>
            <button className="rounded border border-rose-700 px-3 py-1.5 text-sm font-medium text-rose-800 hover:bg-rose-50 disabled:opacity-50" disabled={saving || item.decision === 'rejected'} onClick={() => saveDecision(item, 'rejected')}>Reject</button>
          </div>
        )}
        {item.status === 'new' && item.current && review.previousYear && (
          <div className="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
            <label className="min-w-64 flex-1 text-xs font-medium text-gray-600">
              Link to a previous question by hand
              <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm" value={pickedPreviousKey} onChange={(event) => setManualPrevious((current) => ({ ...current, [currentKey]: event.target.value }))}>
                <option value="">Choose previous question</option>
                {review.previousQuestions.map((question) => <option key={questionKey(question)} value={questionKey(question)}>{question.question_id}{question.part} · {question.prompt.slice(0, 100)}</option>)}
              </select>
            </label>
            <button
              className="rounded bg-folio-600 px-3 py-2 text-sm font-medium text-white hover:bg-folio-700 disabled:opacity-50"
              disabled={saving || !pickedPreviousKey}
              onClick={() => {
                const previous = review.previousQuestions.find((question) => questionKey(question) === pickedPreviousKey);
                if (!previous || !item.current) return;
                onSave([{
                  sheet: item.current.sheet,
                  previous_question_id: previous.question_id,
                  previous_part: previous.part,
                  current_question_id: item.current.question_id,
                  current_part: item.current.part,
                  decision: 'approved',
                  manual: true,
                  match_kind: 'reworded',
                }]);
              }}
            >Link as reworded</button>
          </div>
        )}
      </article>
    );
  };

  return (
    <section aria-label="Annual question review" className="space-y-5">
      <header className="flex flex-col gap-3 rounded-lg border border-folio-200 bg-folio-50 p-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-900">{review.previousYear ? `${review.previousYear} → ${review.surveyYear} review` : `${review.surveyYear} catalogue review`}</h2>
          <p className="mt-1 text-sm text-gray-600">{review.unresolvedCount} matched question parts need a decision. New and removed questions remain visible as change lists.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button className="rounded border border-folio-700 px-3 py-2 text-sm font-semibold text-folio-800 hover:bg-white disabled:cursor-not-allowed disabled:opacity-50" disabled={!selectedLinks.length || saving} onClick={approveSelected}>
            Approve selected
          </button>
          <button className="rounded border border-folio-700 px-3 py-2 text-sm font-semibold text-folio-800 hover:bg-white disabled:cursor-not-allowed disabled:opacity-50" disabled={!bulkLinks.length || saving} onClick={() => onSave(bulkLinks)}>
            Approve unchanged high-score matches
          </button>
        </div>
      </header>

      {groupedItems.map((group) => (
        <section key={group.status} aria-label={`${group.title} questions`} className="space-y-3">
          <div className="flex flex-wrap items-baseline justify-between gap-3 border-b border-gray-200 pb-2">
            <h3 className="text-lg font-semibold text-gray-900">{group.title}</h3>
            <div className="flex items-center gap-3">
              {(group.status === 'reworded' || group.status === 'renumbered') && (
                <label className="flex items-center gap-2 text-sm text-gray-600">
                  <input
                    type="checkbox"
                    aria-label={`Select all high-score ${group.title} matches`}
                    checked={group.items.filter(isBulkReviewItem).length > 0 && group.items.filter(isBulkReviewItem).every((item) => selectedKeys.includes(reviewItemKey(item)))}
                    disabled={saving || !group.items.some(isBulkReviewItem)}
                    onChange={() => toggleGroupSelection(group.items)}
                  />
                  Select all high-score
                </label>
              )}
              <span className="text-sm text-gray-500">{group.items.length}</span>
            </div>
          </div>
          {group.items.length ? group.items.map(renderItem) : <p className="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-500">No {group.title.toLowerCase()} question parts.</p>}
        </section>
      ))}

      <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
        <span className="text-sm text-gray-600">{review.canPublish ? 'All matched question parts have a recorded decision.' : 'Review every proposed match before publishing this year.'}</span>
        <button className="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50" disabled={!review.canPublish || review.status === 'published' || saving || publishing} onClick={onPublish}>
          {publishing ? 'Publishing…' : review.status === 'published' ? 'Published' : 'Publish year'}
        </button>
      </footer>
    </section>
  );
}
