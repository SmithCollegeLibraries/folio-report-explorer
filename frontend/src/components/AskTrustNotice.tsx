import type { ExploratoryAssumption, NlResponse } from '../types';

interface AskTrustNoticeProps {
  mode: NlResponse['mode'];
  reviewRequired?: boolean;
  reviewNotice?: NlResponse['reviewNotice'];
  assumptions?: ExploratoryAssumption[];
}

export default function AskTrustNotice({
  mode,
  reviewRequired = false,
  reviewNotice,
  assumptions = [],
}: AskTrustNoticeProps) {
  if (mode === 'canonical') return null;

  const title = reviewRequired ? 'AI-assisted report — review flagged' : 'AI-assisted report';
  const message = reviewNotice?.message?.trim()
    || 'This report was created with AI assistance.';

  return (
    <aside
      role="note"
      aria-labelledby="ask-trust-notice-title"
      className={`rounded-lg border-l-4 px-4 py-3 shadow-sm ${
        reviewRequired
          ? 'border border-amber-200 border-l-amber-500 bg-amber-50 text-amber-950'
          : 'border border-sky-200 border-l-sky-500 bg-sky-50 text-sky-950'
      }`}
    >
      <h3 id="ask-trust-notice-title" className="text-sm font-semibold tracking-tight">
        {title}
      </h3>
      <p className="mt-1 text-sm leading-5">{message}</p>
      {reviewRequired && (
        <p className="mt-1 text-xs leading-5 text-amber-800">
          This report was flagged for routine review.
        </p>
      )}
      {assumptions.length > 0 && (
        <div className={`mt-3 border-t pt-3 ${reviewRequired ? 'border-amber-200' : 'border-sky-200'}`}>
          <div className="text-xs font-semibold uppercase tracking-wide">Assumptions used</div>
          <ul className="mt-2 space-y-2">
            {assumptions.map((assumption) => (
              <li key={assumption.key} className="text-xs leading-5">
                <span className="font-semibold">{assumption.label}:</span>{' '}
                <span>{assumption.value}</span>
                {assumption.explanation && (
                  <span className={reviewRequired ? 'text-amber-800' : 'text-sky-800'}>
                    {' — '}{assumption.explanation}
                  </span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </aside>
  );
}
