import type { NlResponse } from '../types';

interface ExploratoryRecoveryPanelProps {
  response: NlResponse;
  onRetry: (question: string) => void;
  onRefine: (question: string, suggestion: string) => void;
}

const REJECTED_REFINEMENT_SUGGESTIONS = [
  'Rephrase this as a read-only report.',
  'Specify a date range, grouping, and measures.',
];

function formatFailureCategory(category?: string): string {
  if (!category) return 'Validation failure';
  return category
    .replace(/[^a-zA-Z0-9]+/g, ' ')
    .trim()
    .replace(/^./, (character) => character.toUpperCase());
}

export function ExploratoryRecoveryPanel({ response, onRetry, onRefine }: ExploratoryRecoveryPanelProps) {
  const originalQuestion = response.recoveryContext?.originalQuestion?.trim() || '';
  const isRejected = response.validationSummary?.status === 'rejected';
  const suppliedSuggestions = response.suggestions?.length
    ? response.suggestions
    : response.exploratoryPlan?.suggestions || [];
  const suggestions = isRejected && suppliedSuggestions.length === 0
    ? REJECTED_REFINEMENT_SUGGESTIONS
    : suppliedSuggestions;

  return (
    <section className="rounded-lg border border-amber-200 bg-amber-50 p-5" aria-labelledby="exploratory-recovery-title">
      <h2 id="exploratory-recovery-title" className="text-base font-semibold text-amber-950">
        The request is preserved
      </h2>
      <p className="mt-1 text-sm text-amber-900">
        {isRejected
          ? 'Nothing ran or changed. Ask AI could not safely turn this request into a report. Retry the request or refine one part of it below.'
          : 'No query survived validation. Retry the preserved request or refine one part of it below.'}
      </p>

      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
        {!isRejected && (
          <div className="rounded-md border border-amber-200 bg-white p-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-amber-800">Safe failure category</dt>
            <dd className="mt-1 text-gray-800">{formatFailureCategory(response.validationSummary?.failureCategory)}</dd>
          </div>
        )}
        {(response.attemptedPlan || response.exploratoryPlan?.summary) && (
          <div className="rounded-md border border-amber-200 bg-white p-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-amber-800">Attempted plan</dt>
            <dd className="mt-1 text-gray-800">{response.attemptedPlan || response.exploratoryPlan?.summary}</dd>
          </div>
        )}
      </dl>

      {response.assumptions && response.assumptions.length > 0 && (
        <div className="mt-4">
          <h3 className="text-sm font-semibold text-amber-950">Assumptions used</h3>
          <ul className="mt-2 space-y-2">
            {response.assumptions.map((assumption) => (
              <li key={assumption.key} className="rounded-md border border-amber-200 bg-white p-3">
                <div className="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                  <span>{assumption.label}</span>
                  <span className="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{assumption.value}</span>
                </div>
                <p className="mt-1 text-xs leading-5 text-gray-600">{assumption.explanation}</p>
              </li>
            ))}
          </ul>
        </div>
      )}

      {suggestions.length > 0 && (
        <div className="mt-4">
          <h3 className="text-sm font-semibold text-amber-950">Ways to refine the request</h3>
          <div className="mt-2 space-y-2">
            {suggestions.map((suggestion) => (
              <button
                key={suggestion}
                type="button"
                onClick={() => onRefine(originalQuestion, suggestion)}
                className="block w-full rounded-md border border-amber-200 bg-white px-3 py-2 text-left text-sm text-gray-800 hover:bg-amber-100"
                aria-label={`Refine with: ${suggestion}`}
              >
                {suggestion}
              </button>
            ))}
          </div>
        </div>
      )}

      <button
        type="button"
        onClick={() => onRetry(originalQuestion)}
        disabled={!originalQuestion}
        className="mt-4 rounded-lg bg-folio-700 px-4 py-2 text-sm font-medium text-white hover:bg-folio-800 disabled:opacity-50"
      >
        Retry
      </button>
    </section>
  );
}
