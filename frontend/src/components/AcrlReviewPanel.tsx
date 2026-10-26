import { useEffect, useMemo, useRef, useState, type Ref } from 'react';
import type { AcrlLinkDecision, AcrlLinkDecisionInput, AcrlQuestionPart, AcrlReviewItem, AcrlReviewResponse, AcrlReviewStatus } from '../types/acrl';
import AcrlPromptComparison from './AcrlPromptComparison';

const GROUPS: Array<{ status: AcrlReviewStatus; title: string }> = [
  { status: 'new', title: 'New' },
  { status: 'removed', title: 'Removed' },
  { status: 'renumbered', title: 'Renumbered' },
  { status: 'reworded', title: 'Reworded' },
  { status: 'unchanged', title: 'Unchanged' },
];

const BULK_STATUSES: AcrlReviewStatus[] = ['renumbered', 'reworded', 'unchanged'];

function questionKey(question: AcrlQuestionPart): string {
  return `${question.sheet}|${question.question_id}|${question.part}`;
}

function reviewItemKey(item: AcrlReviewItem): string {
  return `${item.previous ? questionKey(item.previous) : 'none'}→${item.current ? questionKey(item.current) : 'none'}`;
}

function itemSheet(item: AcrlReviewItem): AcrlQuestionPart['sheet'] | null {
  return item.current?.sheet || item.previous?.sheet || null;
}

function isMatched(item: AcrlReviewItem): boolean {
  return item.previous !== null && item.current !== null;
}

function isPendingMatch(item: AcrlReviewItem): boolean {
  return BULK_STATUSES.includes(item.status) && isMatched(item) && !item.decision;
}

function questionId(question: AcrlQuestionPart | null): string {
  return question ? `${question.question_id}${question.part}` : 'question';
}

function itemLabel(item: AcrlReviewItem): string {
  return questionId(item.current || item.previous);
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

function shortPrompt(prompt: string): string {
  if (prompt.length <= 80) return prompt;
  return `${prompt.slice(0, 79).trimEnd()}…`;
}

function uniqueQuestions(questions: Array<AcrlQuestionPart | null>): AcrlQuestionPart[] {
  const seen = new Set<string>();
  return questions.filter((question): question is AcrlQuestionPart => {
    if (!question) return false;
    const key = questionKey(question);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function groupDecisionSummary(items: AcrlReviewItem[]): string {
  if (!items.length) return 'No question parts';
  const approved = items.filter((item) => item.decision === 'approved').length;
  const rejected = items.filter((item) => item.decision === 'rejected').length;
  if (approved === items.length) return `All ${items.length} approved`;
  if (approved + rejected === items.length) return `${approved} approved · ${rejected} rejected`;
  return `${items.length} question${items.length === 1 ? '' : ' parts'}`;
}

export default function AcrlReviewPanel({
  review, reviewHeadingRef, saving, publishing, onSave, onPublish,
}: {
  review: AcrlReviewResponse;
  reviewHeadingRef?: Ref<HTMLHeadingElement>;
  saving: boolean;
  publishing: boolean;
  onSave: (links: AcrlLinkDecisionInput[]) => void;
  onPublish: () => void;
}) {
  const [manualPrevious, setManualPrevious] = useState<Record<string, string>>({});
  const [changingDecisionKey, setChangingDecisionKey] = useState<string | null>(null);
  const [confirmingBulkStatus, setConfirmingBulkStatus] = useState<AcrlReviewStatus | null>(null);
  const rowRefs = useRef(new Map<string, HTMLElement>());
  const groupSummaryRefs = useRef(new Map<AcrlReviewStatus, HTMLElement>());
  const pendingFocusKey = useRef<string | null>(null);
  const pendingBulkFocusStatus = useRef<AcrlReviewStatus | null>(null);

  const surveyItems = useMemo(() => review.items.filter((item) => itemSheet(item) === 'survey'), [review.items]);
  const groupedItems = useMemo(() => GROUPS.map(({ status, title }) => ({
    status,
    title,
    items: surveyItems.filter((item) => item.status === status),
  })), [surveyItems]);
  const summaryCounts = useMemo(() => GROUPS.map(({ status, title }) => ({
    status,
    title,
    count: surveyItems.filter((item) => item.status === status).length,
  })), [surveyItems]);
  const newQuestions = surveyItems.filter((item) => item.status === 'new' && item.current).map((item) => item.current as AcrlQuestionPart);
  const removedQuestions = surveyItems.filter((item) => item.status === 'removed' && item.previous).map((item) => item.previous as AcrlQuestionPart);
  const currentTrends = uniqueQuestions(review.items.map((item) => item.current?.sheet === 'trends' ? item.current : null));
  const previousTrends = uniqueQuestions(review.previousQuestions.map((question) => question.sheet === 'trends' ? question : null));
  const reviewTitle = review.previousYear
    ? `FY ${review.surveyYear} compared with FY ${review.previousYear}`
    : `FY ${review.surveyYear} catalogue review`;
  const pendingCount = surveyItems.filter((item) => isPendingMatch(item)).length;
  const decisionStatus = review.canPublish ? 'Ready to publish' : `${pendingCount} still need a decision`;

  useEffect(() => {
    if (pendingFocusKey.current) {
      const key = pendingFocusKey.current;
      const item = review.items.find((candidate) => reviewItemKey(candidate) === key);
      if (item?.decision) {
        const group = groupedItems.find((candidate) => candidate.items.some((candidateItem) => reviewItemKey(candidateItem) === key));
        if (group && group.items.every((candidate) => candidate.decision)) {
          groupSummaryRefs.current.get(group.status)?.focus();
        } else {
          rowRefs.current.get(key)?.focus();
        }
        pendingFocusKey.current = null;
      }
    }
    if (pendingBulkFocusStatus.current) {
      const group = groupedItems.find((candidate) => candidate.status === pendingBulkFocusStatus.current);
      if (group && group.items.every((item) => item.decision)) {
        groupSummaryRefs.current.get(pendingBulkFocusStatus.current)?.focus();
        pendingBulkFocusStatus.current = null;
      }
    }
  }, [groupedItems, review.items]);

  const saveDecision = (item: AcrlReviewItem, decision: AcrlLinkDecision) => {
    const link = linkInput(item, decision);
    if (!link) return;
    pendingFocusKey.current = reviewItemKey(item);
    onSave([link]);
  };

  const approveAll = (status: AcrlReviewStatus, items: AcrlReviewItem[], confirmed = false) => {
    const pendingItems = items.filter(isPendingMatch);
    if (!pendingItems.length) return;
    const weakItems = pendingItems.filter((item) => item.score < 0.5);
    if (weakItems.length && !confirmed) {
      setConfirmingBulkStatus(status);
      return;
    }
    const links = pendingItems
      .map((item) => linkInput(item, 'approved'))
      .filter((link): link is AcrlLinkDecisionInput => link !== null);
    pendingBulkFocusStatus.current = status;
    setConfirmingBulkStatus(null);
    onSave(links);
  };

  const renderItem = (item: AcrlReviewItem, index: number) => {
    const currentKey = item.current ? questionKey(item.current) : '';
    const pickedPreviousKey = manualPrevious[currentKey] || '';
    const statusLabel = item.status[0].toUpperCase() + item.status.slice(1);
    const key = reviewItemKey(item);
    const label = itemLabel(item);
    const isChanging = changingDecisionKey === key;
    return (
      <article
        key={`${item.previous?.id || 'none'}-${item.current?.id || 'none'}-${index}`}
        ref={(element) => {
          if (element) rowRefs.current.set(key, element);
          else rowRefs.current.delete(key);
        }}
        tabIndex={-1}
        aria-label={`${label} ${statusLabel} survey question`}
        className="space-y-3 rounded-lg border border-gray-200 bg-white p-4 focus:outline-none focus:ring-2 focus:ring-folio-500"
      >
        <div className="flex flex-wrap items-center gap-2">
          <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{statusLabel}</span>
          {item.matchKind && <span className="text-xs text-gray-500">{item.matchKind} · {Math.round(item.score * 100)}% text match</span>}
          {item.decision && <span className={`text-xs font-semibold ${item.decision === 'approved' ? 'text-emerald-700' : 'text-rose-700'}`}>{item.decision}</span>}
        </div>
        <AcrlPromptComparison previous={item.previous} current={item.current} />
        {item.previous && item.current && (
          <div className="flex flex-wrap items-center gap-2">
            {!item.decision || isChanging ? (
              <>
                <button className="rounded border border-emerald-700 px-3 py-1.5 text-sm font-medium text-emerald-800 hover:bg-emerald-50 disabled:opacity-50" disabled={saving || review.status === 'published'} onClick={() => saveDecision(item, 'approved')}>Approve</button>
                <button className="rounded border border-rose-700 px-3 py-1.5 text-sm font-medium text-rose-800 hover:bg-rose-50 disabled:opacity-50" disabled={saving || review.status === 'published'} onClick={() => saveDecision(item, 'rejected')}>Reject</button>
                {item.decision && <button className="rounded px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-100" onClick={() => setChangingDecisionKey(null)}>Cancel</button>}
              </>
            ) : (
              <button
                className="rounded border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                aria-label={`Change decision for ${label}`}
                disabled={saving || review.status === 'published'}
                onClick={() => setChangingDecisionKey(key)}
              >Change</button>
            )}
          </div>
        )}
        {item.status === 'new' && item.current && review.previousYear && (
          <div className="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
            <label className="min-w-64 flex-1 text-xs font-medium text-gray-600">
              Link to a previous question by hand
              <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm" value={pickedPreviousKey} onChange={(event) => setManualPrevious((current) => ({ ...current, [currentKey]: event.target.value }))}>
                <option value="">Choose previous question</option>
                {review.previousQuestions.filter((question) => question.sheet === 'survey').map((question) => <option key={questionKey(question)} value={questionKey(question)}>{question.question_id}{question.part} · {question.prompt.slice(0, 100)}</option>)}
              </select>
            </label>
            <button
              className="rounded bg-folio-600 px-3 py-2 text-sm font-medium text-white hover:bg-folio-700 disabled:opacity-50"
              disabled={saving || !pickedPreviousKey}
              onClick={() => {
                const previous = review.previousQuestions.find((question) => questionKey(question) === pickedPreviousKey && question.sheet === 'survey');
                if (!previous || !item.current) return;
                onSave([{
                  sheet: 'survey',
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
      <section aria-label="Comparison summary" className="space-y-4 rounded-lg border border-folio-200 bg-folio-50 p-4">
        <h2 ref={reviewHeadingRef} tabIndex={-1} className="scroll-mt-6 text-xl font-semibold text-gray-900 outline-none focus:ring-2 focus:ring-folio-500">{reviewTitle}</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          <section aria-labelledby="acrl-new-summary-heading" className="rounded-md border border-folio-200 bg-white p-3">
            <h3 id="acrl-new-summary-heading" className="font-semibold text-gray-900">New survey questions ({newQuestions.length})</h3>
            {newQuestions.length ? (
              <ul className="mt-2 space-y-1 text-sm text-gray-700">{newQuestions.map((question) => <li key={questionKey(question)}><a className="font-medium text-folio-800 underline hover:text-folio-600" href="#acrl-group-new">{questionId(question)} — {question.prompt}</a></li>)}</ul>
            ) : <p className="mt-2 text-sm text-gray-600">No new Survey questions this year.</p>}
          </section>
          <section aria-labelledby="acrl-removed-summary-heading" className="rounded-md border border-gray-200 bg-white p-3">
            <h3 id="acrl-removed-summary-heading" className="font-semibold text-gray-900">Removed survey questions ({removedQuestions.length})</h3>
            {removedQuestions.length ? (
              <ul className="mt-2 space-y-1 text-sm text-gray-700">{removedQuestions.map((question) => <li key={questionKey(question)}><a className="font-medium text-folio-800 underline hover:text-folio-600" href="#acrl-group-removed">{questionId(question)} — {shortPrompt(question.prompt)}</a></li>)}</ul>
            ) : <p className="mt-2 text-sm text-gray-600">No Survey questions were removed.</p>}
          </section>
        </div>
        <p aria-label="Survey change counts" className="text-sm text-gray-700">
          {summaryCounts.filter(({ status }) => ['renumbered', 'reworded', 'unchanged'].includes(status)).map(({ status, title, count }, index) => (
            <span key={status}>{index > 0 ? ', ' : ''}<a className="font-medium text-folio-800 underline hover:text-folio-600" href={`#acrl-group-${status}`}>{count} {title.toLowerCase()}</a></span>
          ))}; {pendingCount} still need a decision
        </p>
        <p className="text-sm text-gray-700">Trends (topical, new each year): {currentTrends.length} questions this year</p>
        <p className="text-sm text-gray-700"><strong>{decisionStatus}.</strong>{' '}<a href="#acrl-publish" className="font-medium text-folio-800 underline hover:text-folio-600">Publish year at the bottom of this review</a>.</p>
      </section>

      <header className="rounded-lg border border-folio-200 bg-folio-50 p-4">
        <p className="text-sm text-gray-600">Review proposed Survey matches below. Trends questions are shown separately and do not need decisions.</p>
      </header>

      {groupedItems.map((group) => {
        const pendingItems = group.items.filter(isPendingMatch);
        const weakItems = pendingItems.filter((item) => item.score < 0.5);
        const allDecided = group.items.length > 0 && group.items.every((item) => item.decision !== null);
        const summary = groupDecisionSummary(group.items);
        const groupLabel = allDecided ? `${group.title} questions · ${summary}` : `${group.title} questions`;
        return (
          <section key={group.status} id={`acrl-group-${group.status}`} aria-label={`${group.title} survey questions`} className={`scroll-mt-6 rounded-lg border p-4 ${group.status === 'new' ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}>
            <details role="group" aria-label={groupLabel} open={group.status === 'new' || !allDecided}>
              <summary
                ref={(element) => {
                  if (element) groupSummaryRefs.current.set(group.status, element);
                  else groupSummaryRefs.current.delete(group.status);
                }}
                className="flex cursor-pointer list-none items-baseline justify-between gap-3 focus:outline-none focus:ring-2 focus:ring-folio-500"
              >
                <span className="text-lg font-semibold text-gray-900">{group.title}</span>
                <span className="text-sm text-gray-500">{summary}</span>
              </summary>
              <div className="mt-3 space-y-3">
                {BULK_STATUSES.includes(group.status) && (
                  <div className="flex flex-wrap items-center gap-2">
                    <button
                      className="rounded border border-folio-700 px-3 py-1.5 text-sm font-semibold text-folio-800 hover:bg-folio-50 disabled:cursor-not-allowed disabled:opacity-50"
                      disabled={!pendingItems.length || saving || review.status === 'published'}
                      onClick={() => approveAll(group.status, group.items)}
                    >Approve all ({pendingItems.length})</button>
                    {confirmingBulkStatus === group.status && (
                      <div role="alertdialog" aria-label={`Confirm bulk approval for ${group.title}`} className="w-full rounded-md border border-amber-300 bg-amber-50 p-3">
                        <p className="text-sm text-amber-950">{weakItems.length} of these have a weak text match (IDs {weakItems.map(itemLabel).join(', ')}). Approve all anyway?</p>
                        <div className="mt-2 flex gap-2">
                          <button className="rounded bg-amber-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-900" disabled={saving} onClick={() => approveAll(group.status, group.items, true)}>Approve all</button>
                          <button className="rounded border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-white" onClick={() => setConfirmingBulkStatus(null)}>Cancel</button>
                        </div>
                      </div>
                    )}
                  </div>
                )}
                {group.items.length ? group.items.map(renderItem) : <p className="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-500">No {group.title.toLowerCase()} Survey question parts.</p>}
              </div>
            </details>
          </section>
        );
      })}

      <section aria-label={`Trends for FY ${review.surveyYear}`} className="space-y-3 rounded-lg border border-violet-200 bg-violet-50 p-4">
        <h3 className="text-lg font-semibold text-gray-900">Trends · topical questions</h3>
        <details role="group" aria-label={`FY ${review.surveyYear} Trends questions (${currentTrends.length})`} className="rounded border border-violet-200 bg-white px-3 py-2">
          <summary className="cursor-pointer text-sm font-semibold text-gray-800">FY {review.surveyYear} Trends questions ({currentTrends.length})</summary>
          {currentTrends.length ? <ul className="mt-2 space-y-1 text-sm text-gray-700">{currentTrends.map((question) => <li key={questionKey(question)}>{questionId(question)} — {question.prompt}</li>)}</ul> : <p className="mt-2 text-sm text-gray-600">No Trends questions were parsed for this year.</p>}
        </details>
        {review.previousYear !== null && (
          <details role="group" aria-label={`FY ${review.previousYear} Trends questions (${previousTrends.length})`} className="rounded border border-violet-200 bg-white px-3 py-2">
            <summary className="cursor-pointer text-sm font-semibold text-gray-800">FY {review.previousYear} Trends questions ({previousTrends.length})</summary>
            {previousTrends.length ? <ul className="mt-2 space-y-1 text-sm text-gray-700">{previousTrends.map((question) => <li key={questionKey(question)}>{questionId(question)} — {question.prompt}</li>)}</ul> : <p className="mt-2 text-sm text-gray-600">No previous-year Trends questions are available.</p>}
          </details>
        )}
      </section>

      <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
        <span className="text-sm text-gray-600">{review.canPublish ? 'All matched question parts have a recorded decision.' : 'Review every proposed match before publishing this year.'}</span>
        <button id="acrl-publish" className="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50" disabled={!review.canPublish || review.status === 'published' || saving || publishing} onClick={onPublish}>
          {publishing ? 'Publishing…' : review.status === 'published' ? 'Published' : 'Publish year'}
        </button>
      </footer>
    </section>
  );
}
