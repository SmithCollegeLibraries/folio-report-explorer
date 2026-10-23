import { FileText, Trash2 } from 'lucide-react';
import { useReportDeletion } from '../hooks/useReportDeletion';
import { usePinnedReports } from '../hooks/usePinnedReports';
import type { ReportTemplate } from '../types';
import { formatCategoryLabel } from '../utils/reports';
import ReportHelp from './ReportHelp';
import ReportPinButton from './ReportPinButton';

interface ReportDetailHeaderProps {
  report: ReportTemplate;
  sourceReportName: string | null;
  sourceMaterialType: string | null;
}

export default function ReportDetailHeader({
  report,
  sourceReportName,
  sourceMaterialType,
}: ReportDetailHeaderProps) {
  const { isAdmin, isDeleting, confirmDelete } = useReportDeletion(report.id, report.name);
  const { pinnedIds, togglePinned } = usePinnedReports();

  return (
    <div className="border-b border-stone-200 px-6 py-6">
      <div className="flex flex-wrap items-start gap-4">
        <div className="rounded-2xl bg-folio-50 p-3 text-folio-700">
          <FileText size={22} />
        </div>
        <div className="min-w-[260px] flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-semibold text-gray-900">{report.name}</h1>
            <span className="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium uppercase tracking-wide text-stone-600">
              {formatCategoryLabel(report.category)}
            </span>
          </div>
          {report.description && <p className="mt-2 max-w-3xl text-sm text-gray-600">{report.description}</p>}
          {report.helpText?.trim() && (
            <div className="mt-3"><ReportHelp reportName={report.name} helpText={report.helpText} /></div>
          )}
          {sourceReportName && sourceMaterialType && (
            <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
              Drillthrough from {sourceReportName} for material type "{sourceMaterialType}".
            </p>
          )}
        </div>
        <div className="flex items-center gap-2">
          <ReportPinButton
            reportName={report.name}
            isPinned={pinnedIds.includes(report.id)}
            onToggle={() => togglePinned(report.id)}
          />
          {isAdmin && (
            <button
              type="button"
              onClick={confirmDelete}
              disabled={isDeleting}
              aria-label="Delete report"
              className="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50 disabled:cursor-wait disabled:opacity-60"
            >
              <Trash2 size={15} /> Delete report
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
