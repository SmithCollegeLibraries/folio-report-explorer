import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FileText, Sparkles } from 'lucide-react';
import { listReports } from '../api/client';
import ReportCatalogControls from '../components/ReportCatalogControls';
import ReportCatalogSection from '../components/ReportCatalogSection';
import GenerateModal from '../components/GenerateModal';
import { usePinnedReports } from '../hooks/usePinnedReports';
import { useReportCatalogFilter } from '../hooks/useReportCatalogFilter';
import type { ReportSummary } from '../types';
import { REPORT_CATEGORIES } from '../utils/reports';

export default function Reports() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [showGenerate, setShowGenerate] = useState(false);

  const { data: groupedReports, isLoading } = useQuery({
    queryKey: ['reports'],
    queryFn: listReports,
  });

  const allReports = useMemo(
    () => REPORT_CATEGORIES.flatMap((category) => groupedReports?.[category.key] ?? []),
    [groupedReports],
  );
  const {
    search,
    setSearch,
    category,
    setCategory,
    categoryCounts,
    filteredReports,
  } = useReportCatalogFilter(allReports);
  const { pinnedIds, togglePinned } = usePinnedReports();
  const pinnedIdSet = useMemo(() => new Set(pinnedIds), [pinnedIds]);
  const reportById = useMemo(
    () => new Map(filteredReports.map((report) => [report.id, report])),
    [filteredReports],
  );
  const pinnedReports = useMemo(
    () => pinnedIds.map((id) => reportById.get(id)).filter((report): report is ReportSummary => Boolean(report)),
    [pinnedIds, reportById],
  );
  const categorySections = useMemo(
    () => REPORT_CATEGORIES.map((item) => ({
      ...item,
      reports: filteredReports.filter((report) => report.category === item.key && !pinnedIdSet.has(report.id)),
    })).filter((section) => section.reports.length > 0),
    [filteredReports, pinnedIdSet],
  );
  const totalReports = allReports.length;

  if (isLoading) {
    return <div className="flex h-96 items-center justify-center text-gray-500">Loading reports...</div>;
  }

  return (
    <div className="min-h-[calc(100vh-56px)] bg-stone-50">
      <div className="mx-auto max-w-6xl px-6 py-8">
        <div className="rounded-3xl border border-stone-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-start gap-4 border-b border-stone-200 px-6 py-6">
            <div className="min-w-[260px] flex-1">
              <div className="flex items-center gap-3">
                <div className="rounded-2xl bg-folio-50 p-3 text-folio-700"><FileText size={22} /></div>
                <div>
                  <h1 className="text-2xl font-semibold text-gray-900">Reports</h1>
                  <p className="mt-1 text-sm text-gray-600">
                    Browse the report catalog and open one report at a time in a focused workspace.
                  </p>
                </div>
              </div>
            </div>
            <button
              type="button"
              onClick={() => setShowGenerate(true)}
              className="flex items-center gap-2 rounded-xl bg-purple-600 px-4 py-3 text-sm font-medium text-white transition-colors hover:bg-purple-700"
            >
              <Sparkles size={16} /> Create with AI
            </button>
          </div>

          <div className="space-y-7 px-6 py-6">
            {totalReports > 0 && (
              <ReportCatalogControls
                search={search}
                category={category}
                categories={REPORT_CATEGORIES
                  .filter((item) => categoryCounts[item.key] > 0)
                  .map((item) => ({ ...item, count: categoryCounts[item.key] }))}
                filteredCount={filteredReports.length}
                totalCount={totalReports}
                onSearchChange={setSearch}
                onCategoryChange={setCategory}
              />
            )}

            {totalReports === 0 && (
              <div className="flex h-64 flex-col items-center justify-center text-center text-stone-400">
                <FileText size={40} className="mb-3 opacity-50" />
                <p className="text-sm">No reports are available yet.</p>
                <button
                  type="button"
                  onClick={() => setShowGenerate(true)}
                  className="mt-3 text-sm text-purple-600 hover:text-purple-700"
                >
                  Create one with AI
                </button>
              </div>
            )}

            {totalReports > 0 && filteredReports.length === 0 && (
              <div className="flex min-h-48 flex-col items-center justify-center rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-6 text-center">
                <p className="text-sm font-medium text-stone-700">No reports match</p>
                <p className="mt-1 text-sm text-stone-500">Try another search or category.</p>
              </div>
            )}

            {filteredReports.length > 0 && (
              <div className="space-y-8">
                {pinnedReports.length > 0 && (
                  <ReportCatalogSection
                    title="Pinned"
                    reports={pinnedReports}
                    pinnedIds={pinnedIdSet}
                    onOpen={(reportId) => navigate(`/reports/${reportId}`)}
                    onTogglePin={togglePinned}
                  />
                )}
                {categorySections.map((section) => (
                  <ReportCatalogSection
                    key={section.key}
                    title={section.label}
                    reports={section.reports}
                    pinnedIds={pinnedIdSet}
                    onOpen={(reportId) => navigate(`/reports/${reportId}`)}
                    onTogglePin={togglePinned}
                  />
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      {showGenerate && (
        <GenerateModal
          onClose={() => setShowGenerate(false)}
          onCreated={() => {
            setShowGenerate(false);
            void queryClient.invalidateQueries({ queryKey: ['reports'] });
          }}
        />
      )}
    </div>
  );
}
