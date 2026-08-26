import type { NlResponse } from '../types';

interface AskTrustNoticeProps {
  generationProvenance?: NlResponse['generationProvenance'];
  provenanceLabel?: NlResponse['provenanceLabel'];
}

export default function AskTrustNotice({
  generationProvenance,
  provenanceLabel,
}: AskTrustNoticeProps) {
  if (!generationProvenance) return null;

  const isAiBuilt = generationProvenance === 'ai_built';
  const label = provenanceLabel || (isAiBuilt ? 'AI-built' : 'Verified pattern');
  const title = label;
  const message = isAiBuilt
    ? 'This report was generated with AI assistance.'
    : 'This report was generated from a verified report pattern.';
  const accentClass = isAiBuilt
    ? 'border border-sky-200 border-l-sky-500 bg-sky-50 text-sky-950'
    : 'border border-emerald-200 border-l-emerald-500 bg-emerald-50 text-emerald-950';

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
    </aside>
  );
}
