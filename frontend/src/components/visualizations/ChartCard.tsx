import { useId, useState, type ReactNode } from 'react';

export const amount = (value: number) => value.toLocaleString('en-US', {
  minimumFractionDigits: 2, maximumFractionDigits: 2,
});
export const percent = (value: number | null) => value === null ? 'n/a'
  : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;

export function HistoryLegend() {
  return <div className="flex flex-wrap gap-5 text-xs text-gray-600 mb-4">
    <span className="flex items-center gap-2"><svg aria-hidden="true" width="26" height="14"><rect x="1" y="1" width="24" height="12" fill="none" stroke="#172f44" strokeWidth="2" strokeDasharray="4 3" /></svg>Stored history</span>
    <span className="flex items-center gap-2"><span aria-hidden="true" className="inline-block w-6 h-3 bg-folio-600" />FOLIO</span>
  </div>;
}

export function ChartCard({ title, explanation, children, table, controls, history = false }: {
  title: string; explanation: string; children: ReactNode; table: ReactNode;
  controls?: ReactNode; history?: boolean;
}) {
  const id = useId();
  const [showData, setShowData] = useState(false);
  return <section aria-labelledby={`${id}-title`} className="bg-white border border-gray-200 rounded-lg p-4 sm:p-6 min-w-0 shadow-sm">
    <h2 id={`${id}-title`} className="text-lg font-semibold text-gray-900">{title}</h2>
    <p className="text-sm text-gray-600 mt-1 mb-4">{explanation}</p>
    {controls}
    {history && <HistoryLegend />}
    {children}
    <button type="button" aria-expanded={showData} aria-controls={`${id}-table`}
      onClick={() => setShowData(!showData)} className="mt-4 text-sm font-medium text-folio-700 underline underline-offset-4">
      Show data
    </button>
    {showData && <div id={`${id}-table`} className="overflow-x-auto mt-4 text-sm [&_th]:text-left [&_th]:p-2 [&_td]:p-2 [&_tr]:border-b [&_table]:w-full [&_caption]:text-left [&_caption]:font-semibold [&_caption]:pb-2">{table}</div>}
  </section>;
}
