import type { ExploratoryAssumption, NlResponse } from '../types';

interface AskTrustNoticeProps {
  generationProvenance?: NlResponse['generationProvenance'];
  provenanceLabel?: NlResponse['provenanceLabel'];
  reviewRequired?: boolean;
  reviewNotice?: NlResponse['reviewNotice'];
  assumptions?: ExploratoryAssumption[];
}

export default function AskTrustNotice({
  generationProvenance,
  provenanceLabel,
  reviewRequired = false,
  reviewNotice,
  assumptions = [],
}: AskTrustNoticeProps) {
  if (!generationProvenance) return null;

  const isAiBuilt = generationProvenance === 'ai_built';
  const label = provenanceLabel || (isAiBuilt ? 'AI-built' : 'Verified pattern');
  const title = reviewRequired ? `${label} — review flagged` : label;
  const message = reviewNotice?.message?.trim()
    || (isAiBuilt
      ? 'This report was generated with AI assistance.'
      : 'This report was generated from a verified report pattern.');
  let accentClass = 'border border-emerald-200 border-l-emerald-500 bg-emerald-50 text-emerald-950';
  let detailClass = 'text-emerald-800';
  let separatorClass = 'border-emerald-200';

  if (isAiBuilt) {
    accentClass = 'border border-sky-200 border-l-sky-500 bg-sky-50 text-sky-950';
    detailClass = 'text-sky-800';
    separatorClass = 'border-sky-200';
  }

  if (reviewRequired) {
    accentClass = 'border border-amber-200 border-l-amber-500 bg-amber-50 text-amber-950';
    detailClass = 'text-amber-800';
    separatorClass = 'border-amber-200';
  }

  return (
    <aside
      role="note"
      aria-labelledby="ask-trust-notice-title"
      className={`rounded-lg border-l-4 px-4 py-3 shadow-sm ${accentClass}`}
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
        <div className={`mt-3 border-t pt-3 ${separatorClass}`}>
          <div className="text-xs font-semibold uppercase tracking-wide">Assumptions used</div>
          <ul className="mt-2 space-y-2">
            {assumptions.map((assumption) => (
              <li key={assumption.key} className="text-xs leading-5">
                <span className="font-semibold">{assumption.label}:</span>{' '}
                <span>{assumption.value}</span>
                {assumption.explanation && (
                  <span className={detailClass}>
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
