import type { AcrlAnswerOptions } from '../types/acrl';

interface Props {
  question: string;
  options: AcrlAnswerOptions;
  value: string;
  disabled: boolean;
  onChange: (value: string) => void;
}

type CheckboxAnswer = { selected: string[]; other: string };
type RadioAnswer = { value: string; other: string };
type GridAnswer = { rows: Record<string, string> };

export default function AcrlStructuredAnswerInput({ question, options, value, disabled, onChange }: Props) {
  const answer = parseAnswer(value, options);

  if (options.kind === 'text') {
    return <textarea className="mt-1 block min-h-10 w-full rounded border border-gray-300 px-3 py-2 disabled:bg-gray-100" value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} />;
  }

  if (options.kind === 'checkbox') {
    const checkbox = answer as CheckboxAnswer;
    return <fieldset className="mt-2 space-y-2" aria-label="Answer choices">
      <legend className="sr-only">Answer choices</legend>
      {options.choices.map((choice) => <label key={choice} className="flex items-start gap-2 text-sm font-normal text-gray-800">
        <input type="checkbox" className="mt-1 rounded border-gray-300" checked={checkbox.selected.includes(choice)} disabled={disabled} onChange={(event) => {
          const selected = event.target.checked
            ? options.choices.filter((option) => checkbox.selected.includes(option) || option === choice)
            : checkbox.selected.filter((option) => option !== choice);
          onChange(JSON.stringify(options.allowOther ? { selected, other: checkbox.other } : { selected }));
        }} />
        <span>{choice}</span>
      </label>)}
      {options.allowOther && <label className="block text-sm font-normal text-gray-700">Other response
        <input aria-label="Other response" className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 disabled:bg-gray-100" value={checkbox.other} disabled={disabled} onChange={(event) => onChange(JSON.stringify({ selected: checkbox.selected, other: event.target.value }))} />
      </label>}
    </fieldset>;
  }

  if (options.kind === 'radio' || options.kind === 'rating') {
    const radio = answer as RadioAnswer;
    const otherChoice = options.choices.find(isOtherLabel);
    return <fieldset className="mt-2 space-y-2" aria-label={options.kind === 'rating' ? 'Rating scale' : 'Answer choices'}>
      <legend className="sr-only">{options.kind === 'rating' ? 'Rating scale' : 'Answer choices'}</legend>
      <select aria-label={question} className="block w-full rounded border border-gray-300 bg-white px-3 py-2 disabled:bg-gray-100" value={radio.value} disabled={disabled} onChange={(event) => {
        const selected = event.target.value;
        onChange(JSON.stringify(options.allowOther ? { value: selected, other: radio.other } : { value: selected }));
      }}>
        <option value="">Choose…</option>
        {options.choices.map((choice) => <option key={choice} value={choice}>{choice}</option>)}
      </select>
      {options.allowOther && otherChoice && <label className="block text-sm font-normal text-gray-700">Other response
        <input aria-label="Other response" className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 disabled:bg-gray-100" value={radio.other} disabled={disabled} onChange={(event) => onChange(JSON.stringify({ value: radio.value, other: event.target.value }))} />
      </label>}
    </fieldset>;
  }

  const grid = answer as GridAnswer;
  return <fieldset className="mt-2 overflow-x-auto" aria-label="Grid response">
    <legend className="sr-only">Grid response</legend>
    <table className="min-w-full border-separate border-spacing-y-1 text-sm">
      <thead><tr><th scope="col" className="px-2 py-1 text-left font-medium text-gray-600">Question</th><th scope="col" className="px-2 py-1 text-left font-medium text-gray-600">Answer</th></tr></thead>
      <tbody>{options.rows.map((row) => {
        if (options.allowOther && isOtherLabel(row)) {
          return <tr key={row}><th scope="row" className="px-2 py-2 text-left font-medium text-gray-800">{row}</th><td className="px-2 py-1">
            <input aria-label={row} className="block w-full rounded border border-gray-300 px-3 py-2 disabled:bg-gray-100" value={grid.rows[row] || ''} disabled={disabled} onChange={(event) => onChange(JSON.stringify({ rows: { ...grid.rows, [row]: event.target.value } }))} />
          </td></tr>;
        }
        const storedValue = grid.rows[row] || '';
        return <tr key={row}><th scope="row" className="px-2 py-2 text-left font-medium text-gray-800">{row}</th><td className="px-2 py-1">
          <select aria-label={row} className="block w-full rounded border border-gray-300 bg-white px-3 py-2 disabled:bg-gray-100" value={storedValue} disabled={disabled} onChange={(event) => onChange(JSON.stringify({ rows: { ...grid.rows, [row]: event.target.value } }))}>
            <option value="">Choose…</option>
            {options.choices.map((choice) => <option key={choice} value={choice}>{choice}</option>)}
          </select>
        </td></tr>;
      })}</tbody>
    </table>
  </fieldset>;
}

function parseAnswer(value: string, options: AcrlAnswerOptions): CheckboxAnswer | RadioAnswer | GridAnswer {
  let parsed: unknown;
  try {
    parsed = JSON.parse(value);
  } catch {
    parsed = null;
  }
  const data = isRecord(parsed) ? parsed : {};
  if (options.kind === 'checkbox') {
    const selected = Array.isArray(data.selected) ? data.selected.filter((choice): choice is string => typeof choice === 'string' && options.choices.includes(choice)) : [];
    return { selected, other: typeof data.other === 'string' ? data.other : '' };
  }
  if (options.kind === 'radio' || options.kind === 'rating') {
    return {
      value: typeof data.value === 'string' && options.choices.includes(data.value) ? data.value : '',
      other: typeof data.other === 'string' ? data.other : '',
    };
  }
  const storedRows = isRecord(data.rows) ? data.rows : {};
  const rows: Record<string, string> = {};
  options.rows.forEach((row) => {
    rows[row] = typeof storedRows[row] === 'string' ? storedRows[row] as string : '';
  });
  return { rows };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isOtherLabel(value: string): boolean {
  return /^other\b/i.test(value.trim());
}
