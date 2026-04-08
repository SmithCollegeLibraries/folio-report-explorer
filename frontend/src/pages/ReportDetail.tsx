import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { getReport, runReport } from '../api/client';
import ResultsTable from '../components/ResultsTable';
import SqlPreview from '../components/SqlPreview';
import ParamInput from '../components/ParamInput';
import { useJobPolling } from '../hooks/useJobPolling';
import {
  ArrowLeft,
  ChevronDown,
  ChevronUp,
  Eye,
  EyeOff,
  FileText,
  Loader2,
  Play,
  Square,
} from 'lucide-react';
import {
  buildBudgetDrillthroughSearch,
  buildSourceReportSearch,
  formatCategoryLabel,
  readReportParamsFromSearch,
  writeReportParamsToSearch,
} from '../utils/reports';

export default function ReportDetail() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const [searchParams, setSearchParams] = useSearchParams();
  const reportId = Number(id);

  const [paramValues, setParamValues] = useState<Record<string, string>>({});
  const [activeJobId, setActiveJobId] = useState<string | null>(null);
  const [showSql, setShowSql] = useState(false);
  const [areParamsCollapsed, setAreParamsCollapsed] = useState(false);
  const [paramsReady, setParamsReady] = useState(false);
  const [lastRunParams, setLastRunParams] = useState<Record<string, string> | null>(null);
  const hydratedFromUrlRef = useRef(false);
  const autoRunTriggeredRef = useRef(false);

  const { job, results, isRunning, error: jobError, cancel: cancelJob, reset: resetJob } =
    useJobPolling(activeJobId);

  const { data: report, isLoading, isError } = useQuery({
    queryKey: ['report', reportId],
    queryFn: () => getReport(reportId),
    enabled: Number.isFinite(reportId),
  });

  const clearAutorunMarker = useCallback(() => {
    if (searchParams.get('autorun') !== String(reportId)) {
      return;
    }

    const next = new URLSearchParams(searchParams);
    next.delete('autorun');
    setSearchParams(next, { replace: true });
  }, [reportId, searchParams, setSearchParams]);

  useEffect(() => {
    hydratedFromUrlRef.current = false;
    autoRunTriggeredRef.current = false;
    setParamValues({});
    setShowSql(false);
    setAreParamsCollapsed(false);
    setParamsReady(false);
    setLastRunParams(null);
    setActiveJobId(null);
    resetJob();
  }, [reportId, resetJob]);

  useEffect(() => {
    if (!report?.parameters || hydratedFromUrlRef.current) {
      return;
    }

    const defaults: Record<string, string> = {};
    for (const parameter of report.parameters) {
      defaults[parameter.name] = parameter.resolvedDefault || '';
    }

    const merged = {
      ...defaults,
      ...readReportParamsFromSearch(reportId, searchParams),
    };

    setParamValues(merged);
    setSearchParams(writeReportParamsToSearch(reportId, merged, searchParams), { replace: true });
    setParamsReady(true);
    hydratedFromUrlRef.current = true;
  }, [report, reportId, searchParams, setSearchParams]);

  const hasRequiredParams = useMemo(() => {
    if (!report) {
      return false;
    }

    return report.parameters.every((parameter) => {
      if (!parameter.required) {
        return true;
      }

      return (paramValues[parameter.name] ?? '').trim() !== '';
    });
  }, [paramValues, report]);

  const updateParamValue = useCallback(
    (name: string, value: string) => {
      setParamValues((previous) => {
        const next = { ...previous, [name]: value };
        setSearchParams(writeReportParamsToSearch(reportId, next, searchParams), { replace: true });
        return next;
      });
    },
    [reportId, searchParams, setSearchParams],
  );

  const runMut = useMutation({
    mutationFn: ({
      params,
      outputMode,
    }: {
      params: Record<string, string>;
      outputMode: 'table' | 'file';
    }) => runReport(reportId, params, { outputMode }),
    onSuccess: (data, variables) => {
      setActiveJobId(data.jobId);
      setLastRunParams({ ...variables.params });
    },
  });

  const handleRun = useCallback(
    (outputMode: 'table' | 'file') => {
      const nextParams = { ...paramValues };
      resetJob();
      runMut.mutate({ params: nextParams, outputMode });
    },
    [paramValues, resetJob, runMut],
  );

  useEffect(() => {
    if (searchParams.get('autorun') !== String(reportId) || autoRunTriggeredRef.current) {
      return;
    }

    if (!paramsReady || !hasRequiredParams) {
      return;
    }

    autoRunTriggeredRef.current = true;
    clearAutorunMarker();
    handleRun('table');
  }, [clearAutorunMarker, handleRun, hasRequiredParams, paramsReady, reportId, searchParams]);

  const handleMaterialTypeDrilldown = useCallback(
    (materialType: string) => {
      if (!report) {
        return;
      }

      const sourceParams = lastRunParams ?? paramValues;
      const nextSearch = buildBudgetDrillthroughSearch(
        searchParams,
        sourceParams,
        materialType,
        report.name,
      );

      navigate({
        pathname: '/reports/3',
        search: `?${nextSearch}`,
      });
    },
    [lastRunParams, navigate, paramValues, report, searchParams],
  );

  const sourceReportId = Number(searchParams.get('sourceReportId'));
  const sourceReportName = searchParams.get('sourceReportName');
  const sourceMaterialType = searchParams.get('sourceMaterialType');
  const sourceHref = Number.isFinite(sourceReportId) && sourceReportId > 0
    ? `/reports/${sourceReportId}?${buildSourceReportSearch(searchParams, sourceReportId)}`
    : null;

  if (!Number.isFinite(reportId)) {
    return (
      <div className="flex h-96 items-center justify-center text-gray-500">
        Invalid report id.
      </div>
    );
  }

  if (isLoading) {
    return (
      <div className="flex h-96 items-center justify-center text-gray-500">
        <Loader2 size={18} className="mr-2 animate-spin" /> Loading report...
      </div>
    );
  }

  if (isError || !report) {
    return (
      <div className="flex h-96 items-center justify-center text-gray-500">
        Report not found.
      </div>
    );
  }

  return (
    <div className="min-h-[calc(100vh-56px)] bg-stone-50">
      <div className="mx-auto max-w-6xl px-6 py-8">
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-3 text-sm text-gray-500">
            <Link to="/reports" className="inline-flex items-center gap-2 hover:text-folio-700">
              <ArrowLeft size={16} /> All reports
            </Link>
            {sourceHref && sourceReportName && (
              <Link to={sourceHref} className="inline-flex items-center gap-2 hover:text-folio-700">
                <ArrowLeft size={16} /> Back to {sourceReportName}
              </Link>
            )}
          </div>

          <div className="rounded-3xl border border-stone-200 bg-white shadow-sm">
            <div className="border-b border-stone-200 px-6 py-6">
              <div className="flex flex-wrap items-start gap-4">
                <div className="rounded-2xl bg-folio-50 p-3 text-folio-700">
                  <FileText size={22} />
                </div>
                <div className="min-w-[260px] flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <h1 className="text-2xl font-semibold text-gray-900">{report.name}</h1>
                    <span className="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium uppercase tracking-wide text-stone-600">
                      {formatCategoryLabel(report.category)}
                    </span>
                  </div>
                  {report.description && (
                    <p className="mt-2 max-w-3xl text-sm text-gray-600">{report.description}</p>
                  )}
                  {sourceReportName && sourceMaterialType && (
                    <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
                      Drillthrough from {sourceReportName} for material type "{sourceMaterialType}".
                    </p>
                  )}
                </div>
              </div>
            </div>

            <div className="space-y-6 px-6 py-6">
              {report.parameters.length > 0 && (
                <section className="space-y-3">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">
                        Parameters
                      </h2>
                      <p className="mt-1 text-xs text-gray-400">
                        {report.parameters.length} filter{report.parameters.length === 1 ? '' : 's'}
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={() => setAreParamsCollapsed((current) => !current)}
                      aria-expanded={!areParamsCollapsed}
                      aria-controls="report-parameters-panel"
                      className="inline-flex items-center gap-2 rounded-xl border border-stone-200 px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-stone-50"
                    >
                      {areParamsCollapsed ? <ChevronDown size={16} /> : <ChevronUp size={16} />}
                      {areParamsCollapsed ? 'Show parameters' : 'Collapse parameters'}
                    </button>
                  </div>

                  {areParamsCollapsed ? (
                    <div className="rounded-2xl border border-dashed border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-600">
                      Parameters are hidden. Expand this section to review or change the filters.
                    </div>
                  ) : (
                    <div
                      id="report-parameters-panel"
                      className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
                    >
                      {report.parameters.map((parameter) => (
                        <ParamInput
                          key={parameter.name}
                          param={parameter}
                          value={paramValues[parameter.name] || ''}
                          options={report.selectOptions?.[parameter.name]}
                          onChange={(value) => updateParamValue(parameter.name, value)}
                        />
                      ))}
                    </div>
                  )}
                </section>
              )}

              <div className="flex flex-wrap items-center gap-2">
                {!isRunning ? (
                  <>
                    <button
                      onClick={() => handleRun('table')}
                      disabled={runMut.isPending || !paramsReady || !hasRequiredParams}
                      className="flex items-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-green-700 disabled:opacity-50"
                    >
                      {runMut.isPending ? (
                        <Loader2 size={14} className="animate-spin" />
                      ) : (
                        <Play size={14} />
                      )}
                      Run Report
                    </button>
                    <button
                      onClick={() => handleRun('file')}
                      disabled={runMut.isPending || !paramsReady || !hasRequiredParams}
                      className="flex items-center gap-2 rounded-xl border border-green-300 px-4 py-2.5 text-sm font-medium text-green-700 transition-colors hover:bg-green-50 disabled:opacity-50"
                    >
                      {runMut.isPending ? (
                        <Loader2 size={14} className="animate-spin" />
                      ) : (
                        <Play size={14} />
                      )}
                      Export CSV
                    </button>
                  </>
                ) : (
                  <button
                    onClick={cancelJob}
                    className="flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-red-700"
                  >
                    <Square size={14} /> Cancel
                  </button>
                )}

                <button
                  onClick={() => setShowSql((current) => !current)}
                  className="flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50"
                >
                  {showSql ? <EyeOff size={14} /> : <Eye size={14} />}
                  {showSql ? 'Hide SQL' : 'View SQL'}
                </button>
              </div>

              {showSql && (
                <div>
                  <SqlPreview sql={report.sqlTemplate} height="220px" />
                  <p className="mt-1 text-xs text-gray-400">
                    Parameters shown as :paramName are bound securely at execution time.
                  </p>
                </div>
              )}

              {isRunning && job && (
                <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4">
                  <div className="flex items-center gap-3">
                    <Loader2 size={18} className="animate-spin text-blue-600" />
                    <div>
                      <div className="text-sm font-medium text-blue-800">
                        {job.status === 'pending_export'
                          ? 'Queued for CSV export...'
                          : job.status === 'pending'
                            ? 'Queued - waiting for worker...'
                            : 'Running report...'}
                      </div>
                      <div className="mt-0.5 text-xs text-blue-600">
                        Job {job.jobId.slice(0, 8)}...
                        {job.startedAt && ` | Started ${job.startedAt}`}
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {runMut.isError && (
                <div className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  Submit error: {String(runMut.error)}
                </div>
              )}
              {jobError && (
                <div className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  Execution error: {jobError}
                </div>
              )}

              {results && (
                <ResultsTable
                  data={results}
                  drillThrough={
                    reportId === 1
                      ? {
                          column: 'Material Type',
                          onClick: (value) => handleMaterialTypeDrilldown(value),
                        }
                      : undefined
                  }
                />
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}