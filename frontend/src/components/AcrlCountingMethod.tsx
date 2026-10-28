import { useState } from 'react';
import type { AcrlAutofillMethod } from '../types/acrl';

interface Props {
  title: string;
  method: AcrlAutofillMethod | null;
  isError?: boolean;
  resultVersion?: number;
}

export default function AcrlCountingMethod({ title, method, isError = false, resultVersion }: Props) {
  const [copyMessage, setCopyMessage] = useState('');

  if (!method && !isError) return null;

  const copySql = async () => {
    if (!method) return;
    try {
      await navigator.clipboard.writeText(method.sql);
      setCopyMessage('SQL copied.');
    } catch {
      setCopyMessage('Could not copy SQL.');
    }
  };

  return <section aria-label={title} className="space-y-2 border-t border-folio-200 pt-3">
    <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
    {isError ? <p role="alert" className="text-sm text-rose-700">Couldn't load how this is counted</p> : method && <>
      {resultVersion !== undefined && resultVersion !== method.queryVersion && <p className="text-sm text-amber-900">This value came from v{resultVersion}; the rules below are v{method.queryVersion}</p>}
      <ul className="list-disc space-y-1 pl-5 text-sm leading-5 text-gray-700">
        {method.summary.map((line, index) => <li key={`${method.queryId}-${index}`}>{line}</li>)}
      </ul>
      <details className="rounded border border-gray-200 bg-white px-3 py-2">
        <summary className="cursor-pointer text-sm font-semibold text-folio-800">SQL</summary>
        <div className="mt-2 space-y-2">
          <pre className="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 font-mono text-xs leading-relaxed text-gray-800">{method.sql}</pre>
          <button type="button" className="rounded border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100" onClick={copySql}>Copy SQL</button>
          {copyMessage && <p role="status" className="text-xs text-gray-600">{copyMessage}</p>}
        </div>
      </details>
    </>}
  </section>;
}
