import type { ExploratoryAssumption } from '../types';

interface ExploratoryAssumptionsPanelProps {
  assumptions: ExploratoryAssumption[];
  repairCount: number;
  onCorrect: (example: string) => void;
}

export function ExploratoryAssumptionsPanel({
  assumptions,
  repairCount,
  onCorrect,
}: ExploratoryAssumptionsPanelProps) {
  if (assumptions.length === 0) return null;

  const validationLabel = repairCount === 0
    ? 'Initial SQL passed validation'
    : `Validated after ${repairCount} automatic repair${repairCount === 1 ? '' : 's'}`;

  return (
    <section className="rounded-lg border border-folio-200 bg-white p-4 shadow-sm" aria-labelledby="exploratory-assumptions-title">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 id="exploratory-assumptions-title" className="text-sm font-semibold text-folio-900">
          Assumptions used
        </h3>
        <span className="text-xs font-medium text-green-700">{validationLabel}</span>
      </div>
      <div className="mt-3 divide-y divide-gray-100">
        {assumptions.map((assumption) => (
          <div key={assumption.key} className="py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-medium text-gray-900">{assumption.label}</span>
              <span className="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">
                {assumption.value}
              </span>
              <span className={`rounded-full border px-2 py-0.5 text-[11px] font-medium ${
                assumption.source === 'explicit'
                  ? 'border-blue-200 bg-blue-50 text-blue-700'
                  : 'border-amber-200 bg-amber-50 text-amber-700'
              }`}>
                {assumption.source === 'explicit' ? 'Explicit' : 'Default'}
              </span>
            </div>
            <p className="mt-1 text-xs leading-5 text-gray-600">{assumption.explanation}</p>
            <button
              type="button"
              onClick={() => onCorrect(assumption.correctionExample)}
              className="mt-2 text-xs font-medium text-folio-700 hover:text-folio-900 hover:underline"
              aria-label={`Correct ${assumption.label} assumption`}
            >
              Correct
            </button>
          </div>
        ))}
      </div>
    </section>
  );
}
