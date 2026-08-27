import { Pencil, Sparkles } from 'lucide-react';

interface AskReuseNoticeProps {
  onEditSql: () => void;
  onGenerateFresh: () => void;
}

export default function AskReuseNotice({ onEditSql, onGenerateFresh }: AskReuseNoticeProps) {
  return (
    <aside
      role="note"
      aria-labelledby="ask-reuse-notice-title"
      className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between"
    >
      <div>
        <h3 id="ask-reuse-notice-title" className="text-sm font-semibold text-slate-800">
          Reused previous query
        </h3>
        <p className="mt-0.5 text-sm text-slate-600">
          Report Explorer reran a previous successful query for this request.
        </p>
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={onEditSql}
          className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:border-slate-300 hover:bg-slate-50"
        >
          <Pencil size={12} />
          Edit SQL
        </button>
        <button
          type="button"
          onClick={onGenerateFresh}
          className="inline-flex items-center gap-1.5 rounded-md bg-folio-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-folio-800"
        >
          <Sparkles size={12} />
          Ask AI for new SQL
        </button>
      </div>
    </aside>
  );
}
