import { useState } from 'react';
import { runAcrlAutofill } from '../api/acrl';
import useAcrlAutofillMethods from '../hooks/useAcrlAutofillMethods';
import { errorMessage } from '../utils/errorMessage';
import AcrlCountingMethod from './AcrlCountingMethod';
import AcrlFileStatus from './AcrlFileStatus';
import type { AcrlAutofillResponse, AcrlSheet } from '../types/acrl';

interface TypedPart {
  key: string;
  label: string;
}

interface AutofillDefinition {
  noteLabel: string;
  typedParts: TypedPart[];
}

interface AcrlAutofillPanelProps {
  year: number;
  sheet: AcrlSheet;
  questionId: string;
  part: string;
  currentValue: string | null;
  currentNote: string;
  disabled: boolean;
  onUseValue: (value: string, note: string) => void;
}

const NO_TYPED_PARTS: TypedPart[] = [];

const definitions: Record<string, AutofillDefinition> = {
  'survey|60|A': {
    noteLabel: 'physical circulation',
    typedParts: NO_TYPED_PARTS,
  },
  'survey|81|': {
    noteLabel: 'consortial lending',
    typedParts: [
      { key: 'illiadReturnables', label: 'ILLiad returnables' },
      { key: 'illiadNonReturnables', label: 'ILLiad non-returnables' },
    ],
  },
  'survey|82|': {
    noteLabel: 'consortial borrowing',
    typedParts: [
      { key: 'illiadReturnables', label: 'ILLiad returnables' },
      { key: 'illiadNonReturnables', label: 'ILLiad non-returnables' },
    ],
  },
  'survey|40|A': {
    noteLabel: 'physical book titles',
    typedParts: NO_TYPED_PARTS,
  },
  'survey|40a|': {
    noteLabel: 'physical volumes',
    typedParts: NO_TYPED_PARTS,
  },
  'survey|40|B': {
    noteLabel: 'digital book titles',
    typedParts: [{ key: 'edsCustomCatalog', label: 'EDS custom catalog (minus DDA packages)' }],
  },
  'survey|42|A': {
    noteLabel: 'physical media',
    typedParts: NO_TYPED_PARTS,
  },
  'survey|42|B': {
    noteLabel: 'digital media titles',
    typedParts: [{ key: 'edsCustomCatalog', label: 'EDS custom catalog (minus DDA packages)' }],
  },
  'survey|43|A': {
    noteLabel: 'physical serials',
    typedParts: NO_TYPED_PARTS,
  },
};

function formatCount(value: number): string {
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function formatDate(date: string): string {
  return new Intl.DateTimeFormat('en-US', {
    month: 'long',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(`${date}T12:00:00Z`));
}

function isSnapshotFarFromYearEnd(year: number, result: AcrlAutofillResponse): boolean {
  if (result.kind !== 'snapshot' || !result.asOf) return false;
  const yearEnd = Date.parse(`${year}-06-30T00:00:00Z`);
  const snapshotDate = Date.parse(`${result.asOf}T00:00:00Z`);
  return Number.isFinite(yearEnd) && Number.isFinite(snapshotDate)
    && Math.abs(snapshotDate - yearEnd) > 30 * 24 * 60 * 60 * 1000;
}

export default function AcrlAutofillPanel({ year, sheet, questionId, part, currentValue, currentNote, disabled, onUseValue }: AcrlAutofillPanelProps) {
  const [result, setResult] = useState<AcrlAutofillResponse | null>(null);
  const [typedValues, setTypedValues] = useState<Record<string, string>>({});
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  const [confirmOverwrite, setConfirmOverwrite] = useState(false);
  const definition = definitions[`${sheet}|${questionId}|${part}`];
  const methodsQuery = useAcrlAutofillMethods(sheet, questionId, part, Boolean(definition));

  if (!definition) return null;

  const loadSuggestion = async () => {
    setIsLoading(true);
    setError('');
    setResult(null);
    setTypedValues({});
    setConfirmOverwrite(false);
    try {
      setResult(await runAcrlAutofill(year, sheet, questionId, part));
    } catch (requestError) {
      setError(errorMessage(requestError));
    } finally {
      setIsLoading(false);
    }
  };

  const typedPartsReady = definition.typedParts.every(({ key }) => {
    const raw = typedValues[key] ?? '';
    return raw !== '' && Number.isInteger(Number(raw)) && Number(raw) >= 0;
  });
  const total = result ? result.value + definition.typedParts.reduce((sum, { key }) => {
    const raw = typedValues[key] ?? '';
    return sum + (raw === '' || !Number.isFinite(Number(raw)) ? 0 : Number(raw));
  }, 0) : 0;
  const hasCurrentValue = currentValue !== null && currentValue.trim() !== '';
  const canUseValue = !disabled && Boolean(result) && typedPartsReady && (!hasCurrentValue || confirmOverwrite);

  const useSuggestion = () => {
    if (!result || !canUseValue) return;
    const runDate = result.ranAt.slice(0, 10);
    const timing = result.kind === 'dated'
      ? `FY${year}, run ${runDate}`
      : `as of ${result.asOf}`;
    const noteParts = [
      `FOLIO ${definition.noteLabel} ${formatCount(result.value)} (query ${result.queryId} v${result.queryVersion}, ${timing})`,
      ...definition.typedParts.map(({ key, label }) => `${label} ${formatCount(Number(typedValues[key]))}`),
    ];
    const combinedNote = [currentNote.trim(), noteParts.join(' + ')].filter(Boolean).join('\n');
    onUseValue(String(total), combinedNote);
  };

  return <div className="space-y-2">
    <button type="button" className="rounded border border-folio-700 bg-white px-3 py-1.5 text-sm font-semibold text-folio-800 hover:bg-folio-50 disabled:opacity-50" disabled={disabled || isLoading} onClick={loadSuggestion}>
      {isLoading ? 'Querying FOLIO… collection counts can take a minute or more.' : 'Fill from FOLIO'}
    </button>
    <AcrlFileStatus message={isLoading ? 'Querying FOLIO… collection counts can take a minute or more.' : error ? `Could not get a FOLIO value. ${error}` : null} busy={isLoading} />
    {result && <section aria-label="FOLIO suggestion" className="space-y-3 rounded-lg border border-folio-200 bg-folio-50/60 p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm font-semibold text-gray-900">FOLIO count: {formatCount(result.value)}</p>
        {result.kind === 'snapshot' && result.asOf
          ? <p className="text-xs font-medium text-gray-600">As of {formatDate(result.asOf)}</p>
          : <p className="text-xs font-medium text-gray-600">FY{year}</p>}
      </div>
      <p className="text-xs font-medium text-gray-600">query {result.queryId} v{result.queryVersion}</p>
      <AcrlCountingMethod title="How Fill from FOLIO counts this now" method={methodsQuery.method} isError={methodsQuery.isError} resultVersion={result.queryVersion} />
      {isSnapshotFarFromYearEnd(year, result) && <p className="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">This counts FOLIO today, not on June 30.</p>}
      {definition.typedParts.length > 0 && <div className="grid gap-3 sm:grid-cols-2">
        {definition.typedParts.map(({ key, label }) => <label key={key} className="text-sm font-medium text-gray-700">{label}
          <input type="number" min="0" step="1" className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={typedValues[key] ?? ''} onChange={(event) => setTypedValues((values) => ({ ...values, [key]: event.target.value }))} />
        </label>)}
      </div>}
      {definition.typedParts.length > 0 && <p className="text-xs text-gray-600">These typed-in counts come from outside FOLIO.{!typedPartsReady && ' Enter a number for each; enter 0 when there is none.'}</p>}
      <p className="border-t border-folio-200 pt-2 text-sm font-semibold text-gray-900">Total: {formatCount(total)}</p>
      {hasCurrentValue && <div className="space-y-2 rounded border border-amber-300 bg-white p-3 text-sm text-amber-950">
        <p>Current value: {formatCount(Number(currentValue))}</p>
        <label className="flex items-center gap-2 font-medium">
          <input type="checkbox" checked={confirmOverwrite} onChange={(event) => setConfirmOverwrite(event.target.checked)} />
          I confirm replacing the current answer
        </label>
      </div>}
      <button type="button" className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white hover:bg-folio-800 disabled:cursor-not-allowed disabled:opacity-50" disabled={!canUseValue} onClick={useSuggestion}>Use this value</button>
    </section>}
  </div>;
}
