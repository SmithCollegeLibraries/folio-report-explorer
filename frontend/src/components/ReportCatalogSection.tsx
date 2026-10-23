import { ChevronDown } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReportSummary } from '../types';
import ReportCatalogRow from './ReportCatalogRow';

interface ReportCatalogSectionProps {
  title: string;
  reports: ReportSummary[];
  pinnedIds: Set<number>;
  onOpen: (reportId: number) => void;
  onTogglePin: (reportId: number) => void;
}

export default function ReportCatalogSection({
  title,
  reports,
  pinnedIds,
  onOpen,
  onTogglePin,
}: ReportCatalogSectionProps) {
  const [isCollapsed, setIsCollapsed] = useState(false);
  const contentId = useId();

  return (
    <section className="space-y-3" aria-label={`${title} reports`}>
      <div className="flex items-center gap-3">
        <h2 className="text-lg font-semibold text-stone-900">{title}</h2>
        <span className="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium tabular-nums text-stone-600">
          {reports.length}
        </span>
        <button
          type="button"
          onClick={() => setIsCollapsed((current) => !current)}
          aria-label={`${isCollapsed ? 'Expand' : 'Collapse'} ${title} section`}
          aria-expanded={!isCollapsed}
          aria-controls={contentId}
          className="rounded-lg p-1.5 text-stone-400 transition hover:bg-stone-100 hover:text-stone-700"
        >
          <ChevronDown size={17} className={`transition-transform ${isCollapsed ? '-rotate-90' : ''}`} />
        </button>
      </div>
      {!isCollapsed && (
        <div id={contentId} className="overflow-hidden rounded-2xl border border-stone-200 bg-white">
          {reports.map((report, index) => (
            <div key={report.id} className={index === 0 ? '' : 'border-t border-stone-200'}>
              <ReportCatalogRow
                report={report}
                isPinned={pinnedIds.has(report.id)}
                onOpen={() => onOpen(report.id)}
                onTogglePin={() => onTogglePin(report.id)}
              />
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
