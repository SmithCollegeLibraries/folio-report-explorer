import { ChevronRight, FileText, Sparkles } from 'lucide-react';
import type { ReportSummary } from '../types';
import ReportPinButton from './ReportPinButton';

interface ReportCatalogRowProps {
  report: ReportSummary;
  isPinned: boolean;
  onOpen: () => void;
  onTogglePin: () => void;
}

export default function ReportCatalogRow({ report, isPinned, onOpen, onTogglePin }: ReportCatalogRowProps) {
  return (
    <div className="group flex items-start gap-3 px-4 py-4 transition-colors hover:bg-stone-50 sm:px-5">
      <button type="button" onClick={onOpen} className="flex min-w-0 flex-1 items-start gap-3 text-left">
        <div className="mt-0.5 rounded-lg bg-folio-50 p-2 text-folio-700">
          <FileText size={16} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold text-stone-900">{report.name}</span>
            <span className="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-stone-500">
              {report.parameterCount} param{report.parameterCount === 1 ? '' : 's'}
            </span>
            {report.createdBy === 'ai' && (
              <span className="flex items-center gap-1 rounded-full bg-purple-50 px-2 py-0.5 text-[11px] font-medium text-purple-700">
                <Sparkles size={10} /> AI
              </span>
            )}
          </div>
          <p className="mt-1 line-clamp-2 text-sm leading-relaxed text-stone-600">{report.description}</p>
        </div>
        <ChevronRight size={18} className="mt-1 flex-shrink-0 text-stone-300 transition-transform group-hover:translate-x-0.5" />
      </button>
      <ReportPinButton reportName={report.name} isPinned={isPinned} onToggle={onTogglePin} />
    </div>
  );
}
