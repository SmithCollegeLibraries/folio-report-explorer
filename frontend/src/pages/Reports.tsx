import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronRight, FileText, Sparkles, Trash2 } from 'lucide-react';
import { deleteReport, listReports } from '../api/client';
import GenerateModal from '../components/GenerateModal';
import type { ReportSummary } from '../types';
import { REPORT_CATEGORIES, formatCategoryLabel } from '../utils/reports';

function ReportRow({
  report,
  onOpen,
  onDelete,
}: {
  report: ReportSummary;
  onOpen: () => void;
  onDelete: () => void;
}) {
  return (
    <div className="group flex items-start gap-3 px-4 py-4 transition-colors hover:bg-gray-50">
      <button
        type="button"
        onClick={onOpen}
        className="flex min-w-0 flex-1 items-start gap-3 text-left"
      >
        <div className="mt-0.5 rounded-lg bg-folio-50 p-2 text-folio-700">
          <FileText size={16} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-semibold text-gray-900">{report.name}</span>
            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-gray-500">
              {report.parameterCount} param{report.parameterCount === 1 ? '' : 's'}
            </span>
            {report.createdBy === 'ai' && (
              <span className="flex items-center gap-1 rounded-full bg-purple-50 px-2 py-0.5 text-[11px] font-medium text-purple-700">
                <Sparkles size={10} /> AI
              </span>
            )}
          </div>
          <p className="mt-1 line-clamp-2 text-sm text-gray-600">{report.description}</p>
        </div>
        <ChevronRight
          size={18}
          className="mt-1 flex-shrink-0 text-gray-300 transition-transform group-hover:translate-x-0.5"
        />
      </button>
      <button
        type="button"
        onClick={onDelete}
        className="rounded-lg border border-red-200 p-2 text-red-600 opacity-0 transition hover:bg-red-50 group-hover:opacity-100"
        aria-label={`Delete ${report.name}`}
      >
        <Trash2 size={14} />
      </button>
    </div>
  );
}

export default function Reports() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [showGenerate, setShowGenerate] = useState(false);

  const { data: groupedReports, isLoading } = useQuery({
    queryKey: ['reports'],
    queryFn: listReports,
  });

  const deleteMut = useMutation({
    mutationFn: (id: number) => deleteReport(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reports'] });
    },
  });

  const sections = REPORT_CATEGORIES.map((category) => ({
    ...category,
    reports: groupedReports?.[category.key] ?? [],
  })).filter((section) => section.reports.length > 0);

  const totalReports = sections.reduce((count, section) => count + section.reports.length, 0);

  if (isLoading) {
    return (
      <div className="flex h-96 items-center justify-center text-gray-500">
        Loading reports...
      </div>
    );
  }

  return (
    <div className="min-h-[calc(100vh-56px)] bg-stone-50">
      <div className="mx-auto max-w-6xl px-6 py-8">
        <div className="rounded-3xl border border-stone-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-start gap-4 border-b border-stone-200 px-6 py-6">
            <div className="min-w-[260px] flex-1">
              <div className="flex items-center gap-3">
                <div className="rounded-2xl bg-folio-50 p-3 text-folio-700">
                  <FileText size={22} />
                </div>
                <div>
                  <h1 className="text-2xl font-semibold text-gray-900">Reports</h1>
                  <p className="mt-1 text-sm text-gray-600">
                    Browse the report catalog and open one report at a time in a focused workspace.
                  </p>
                </div>
              </div>
            </div>
            <div className="flex items-center gap-3">
              <div className="rounded-2xl bg-stone-100 px-4 py-3 text-sm text-stone-600">
                <span className="text-lg font-semibold text-stone-900">{totalReports}</span> available
              </div>
              <button
                onClick={() => setShowGenerate(true)}
                className="flex items-center gap-2 rounded-xl bg-purple-600 px-4 py-3 text-sm font-medium text-white transition-colors hover:bg-purple-700"
              >
                <Sparkles size={16} /> Create with AI
              </button>
            </div>
          </div>

          <div className="px-6 py-6">
            {sections.length === 0 ? (
              <div className="flex h-64 flex-col items-center justify-center text-center text-gray-400">
                <FileText size={40} className="mb-3 opacity-50" />
                <p className="text-sm">No reports are available yet.</p>
                <button
                  onClick={() => setShowGenerate(true)}
                  className="mt-3 text-sm text-purple-600 hover:text-purple-700"
                >
                  Create one with AI
                </button>
              </div>
            ) : (
              <div className="space-y-8">
                {sections.map((section) => (
                  <section key={section.key} className="space-y-3">
                    <div className="flex items-center gap-3">
                      <h2 className="text-lg font-semibold text-gray-900">
                        {formatCategoryLabel(section.key)}
                      </h2>
                      <span className="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-600">
                        {section.reports.length}
                      </span>
                    </div>
                    <div className="overflow-hidden rounded-2xl border border-stone-200 bg-white">
                      {section.reports.map((report, index) => (
                        <div
                          key={report.id}
                          className={index === 0 ? '' : 'border-t border-stone-200'}
                        >
                          <ReportRow
                            report={report}
                            onOpen={() => navigate(`/reports/${report.id}`)}
                            onDelete={() => {
                              if (confirm(`Delete "${report.name}"?`)) {
                                deleteMut.mutate(report.id);
                              }
                            }}
                          />
                        </div>
                      ))}
                    </div>
                  </section>
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
            queryClient.invalidateQueries({ queryKey: ['reports'] });
          }}
        />
      )}
    </div>
  );
}
