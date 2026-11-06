import { useCallback, useId, useRef, useState, type ReactNode } from 'react';
import { ChartEnlargementDialog, chartEnlargementOpenEvent } from './ChartEnlargementDialog';
import { useChartLabelSize } from './ChartLabelSize';

export const amount = (value: number) => value.toLocaleString('en-US', {
  minimumFractionDigits: 2, maximumFractionDigits: 2,
});
export const percent = (value: number | null) => value === null ? 'n/a'
  : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;

export function HistoryLegend() {
  const patternId = useId().replace(/:/g, '');
  const labelSize = useChartLabelSize();
  return <div className="chart-history-legend flex flex-wrap gap-5 text-gray-600 mb-4" style={{ fontSize: labelSize }}>
    <span className="flex items-center gap-2"><svg aria-hidden="true" width="26" height="14">
      <defs><pattern id={patternId} width="6" height="6" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">
        <rect width="6" height="6" fill="#f8fafc" /><path d="M 0 0 V 6" stroke="#334155" strokeWidth="2" />
      </pattern></defs>
      <rect x="1" y="1" width="24" height="12" fill={`url(#${patternId})`} stroke="#334155" strokeWidth="1" />
    </svg>Stored history</span>
    <span className="flex items-center gap-2"><span aria-hidden="true" className="inline-block w-6 h-3 bg-folio-600" />FOLIO</span>
  </div>;
}

export function HistoryPattern({ id }: { id: string }) {
  return <defs><pattern id={id} width="6" height="6" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">
    <rect width="6" height="6" fill="#f8fafc" />
    <path d="M 0 0 V 6" stroke="#334155" strokeWidth="2" />
  </pattern></defs>;
}

export function ChartCard({ title, explanation, children, table, controls, history = false }: {
  title: string; explanation: string; children: ReactNode; table: ReactNode;
  controls?: ReactNode; history?: boolean;
}) {
  const id = useId();
  const [showData, setShowData] = useState(false);
  const [enlarged, setEnlarged] = useState(false);
  const enlargeRef = useRef<HTMLButtonElement>(null);
  const closeEnlarged = useCallback(() => setEnlarged(false), []);
  const openEnlarged = () => {
    window.dispatchEvent(new Event(chartEnlargementOpenEvent));
    setEnlarged(true);
  };
  return <section aria-labelledby={`${id}-title`} className="bg-white border border-gray-200 rounded-lg p-4 sm:p-6 min-w-0 shadow-sm">
    <header className="flex items-start justify-between gap-3">
      <h2 id={`${id}-title`} className="text-lg font-semibold text-gray-900">{title}</h2>
      <button ref={enlargeRef} type="button" aria-haspopup="dialog" onClick={openEnlarged}
        className="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
        Enlarge
      </button>
    </header>
    <p className="text-sm text-gray-600 mt-1 mb-4">{explanation}</p>
    {controls}
    {history && <HistoryLegend />}
    {children}
    <button type="button" aria-expanded={showData} aria-controls={`${id}-table`}
      onClick={() => setShowData(!showData)} className="mt-4 text-sm font-medium text-folio-700 underline underline-offset-4">
      Show data
    </button>
    {showData && <div id={`${id}-table`} className="overflow-x-auto mt-4 text-sm [&_th]:text-left [&_th]:p-2 [&_td]:p-2 [&_tr]:border-b [&_table]:w-full [&_caption]:text-left [&_caption]:font-semibold [&_caption]:pb-2">{table}</div>}
    {enlarged && <ChartEnlargementDialog title={title} explanation={explanation} controls={controls} legend={history ? <HistoryLegend /> : null}
      triggerRef={enlargeRef} onClose={closeEnlarged}>{children}</ChartEnlargementDialog>}
  </section>;
}
