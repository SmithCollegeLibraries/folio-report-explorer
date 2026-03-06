import { useState, useEffect, useMemo } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  listReports,
  getReport,
  runReport,
  createReport,
  deleteReport,
  generateReportTemplate,
  convertReportFromPhp,
} from '../api/client';
import { useJobPolling } from '../hooks/useJobPolling';
import SqlPreview from '../components/SqlPreview';
import ResultsTable from '../components/ResultsTable';
import type {
  ReportSummary,
  ReportCategory,
  ReportGenerateResponse,
} from '../types';
import {
  FileText,
  Play,
  Sparkles,
  Loader2,
  Square,
  ChevronDown,
  ChevronRight,
  Trash2,
  Plus,
  Send,
  Eye,
  EyeOff,
  X,
} from 'lucide-react';

const CATEGORIES: { key: ReportCategory | 'all'; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'acquisitions', label: 'Acquisitions' },
  { key: 'circulation', label: 'Circulation' },
  { key: 'inventory', label: 'Inventory' },
  { key: 'finance', label: 'Finance' },
  { key: 'users', label: 'Users' },
  { key: 'other', label: 'Other' },
];

export default function Reports() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const { id: reportIdParam } = useParams<{ id: string }>();

  const [activeCategory, setActiveCategory] = useState<ReportCategory | 'all'>('all');
  const [expandedReportId, setExpandedReportId] = useState<number | null>(
    reportIdParam ? Number(reportIdParam) : null,
  );
  const [showGenerate, setShowGenerate] = useState(false);

  // Sync URL → expanded state when the list loads (covers direct-link navigation)
  const { data: groupedReports, isLoading } = useQuery({
    queryKey: ['reports'],
    queryFn: listReports,
  });

  // Flatten for display
  const reports = useMemo(() => {
    if (!groupedReports) return [];
    const all: ReportSummary[] = [];
    for (const cat of Object.keys(groupedReports)) {
      all.push(...groupedReports[cat]);
    }
    if (activeCategory === 'all') return all;
    return all.filter((r) => r.category === activeCategory);
  }, [groupedReports, activeCategory]);

  // Count per category
  const categoryCounts = useMemo(() => {
    if (!groupedReports) return {};
    const counts: Record<string, number> = { all: 0 };
    for (const [cat, items] of Object.entries(groupedReports)) {
      counts[cat] = items.length;
      counts.all += items.length;
    }
    return counts;
  }, [groupedReports]);

  // Open the right report when data arrives after a direct-link navigation
  useEffect(() => {
    if (reportIdParam && groupedReports) {
      const targetId = Number(reportIdParam);
      setExpandedReportId(targetId);
      // Switch to the correct category tab so the report is visible
      const allItems = Object.values(groupedReports).flat();
      const match = allItems.find((r) => r.id === targetId);
      if (match) setActiveCategory(match.category as ReportCategory);
    }
  }, [reportIdParam, groupedReports]);

  const handleToggleReport = (id: number) => {
    if (expandedReportId === id) {
      setExpandedReportId(null);
      navigate('/reports', { replace: true });
    } else {
      setExpandedReportId(id);
      navigate(`/reports/${id}`, { replace: true });
    }
  };

  const deleteMut = useMutation({
    mutationFn: (id: number) => deleteReport(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reports'] });
      setExpandedReportId(null);
      navigate('/reports', { replace: true });
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96 text-gray-500">
        Loading reports…
      </div>
    );
  }

  return (
    <div className="flex flex-col h-[calc(100vh-56px)]">
      {/* Header */}
      <div className="p-4 bg-white border-b flex items-center gap-4 flex-shrink-0 flex-wrap">
        <div className="flex items-center gap-2">
          <FileText size={20} className="text-folio-600" />
          <h2 className="text-lg font-semibold">Reports</h2>
        </div>
        <p className="text-sm text-gray-500">
          Pre-built parameterized reports ready to run.
        </p>
        <div className="ml-auto">
          <button
            onClick={() => setShowGenerate(true)}
            className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700 transition-colors"
          >
            <Sparkles size={16} /> Create with AI
          </button>
        </div>
      </div>

      {/* Category tabs */}
      <div className="px-4 pt-3 bg-white border-b flex gap-1 flex-shrink-0 overflow-x-auto">
        {CATEGORIES.map(({ key, label }) => (
          <button
            key={key}
            onClick={() => setActiveCategory(key)}
            className={`px-4 py-2 text-sm font-medium rounded-t-lg transition-colors ${
              activeCategory === key
                ? 'bg-folio-50 text-folio-700 border border-b-0 border-folio-200'
                : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'
            }`}
          >
            {label}
            {(categoryCounts[key] ?? 0) > 0 && (
              <span className="ml-1.5 text-xs bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded-full">
                {categoryCounts[key]}
              </span>
            )}
          </button>
        ))}
      </div>

      {/* Report list */}
      <div className="flex-1 overflow-y-auto p-4">
        {reports.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 text-gray-400">
            <FileText size={40} className="mb-3 opacity-50" />
            <p className="text-sm">No reports in this category.</p>
            <button
              onClick={() => setShowGenerate(true)}
              className="mt-3 text-sm text-purple-600 hover:text-purple-700"
            >
              Create one with AI
            </button>
          </div>
        ) : (
          <div className="space-y-3 max-w-4xl mx-auto">
            {reports.map((report) => (
              <div key={report.id} className="border rounded-lg bg-white">
                {/* Report card header */}
                <button
                  onClick={() => handleToggleReport(report.id)}
                  className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 transition-colors"
                >
                  {expandedReportId === report.id ? (
                    <ChevronDown size={16} className="text-gray-400" />
                  ) : (
                    <ChevronRight size={16} className="text-gray-400" />
                  )}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="font-medium text-sm">{report.name}</span>
                      <span className="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">
                        {report.category}
                      </span>
                      {report.createdBy === 'ai' && (
                        <span className="text-xs bg-purple-50 text-purple-600 px-2 py-0.5 rounded flex items-center gap-1">
                          <Sparkles size={10} /> AI
                        </span>
                      )}
                    </div>
                    <p className="text-xs text-gray-500 mt-0.5 truncate">
                      {report.description}
                    </p>
                  </div>
                  <span className="text-xs text-gray-400">
                    {report.parameterCount} param{report.parameterCount !== 1 ? 's' : ''}
                  </span>
                </button>

                {/* Expanded: Report run panel */}
                {expandedReportId === report.id && (
                  <ReportRunPanel
                    reportId={report.id}
                    onDelete={() => {
                      if (confirm(`Delete "${report.name}"?`)) {
                        deleteMut.mutate(report.id);
                      }
                    }}
                  />
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* AI Generate Modal */}
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

// ─── Report Run Panel ───────────────────────────────────────────

function ReportRunPanel({
  reportId,
  onDelete,
}: {
  reportId: number;
  onDelete: () => void;
}) {
  const [paramValues, setParamValues] = useState<Record<string, string>>({});
  const [activeJobId, setActiveJobId] = useState<string | null>(null);
  const [showSql, setShowSql] = useState(false);

  const { job, results, isRunning, error: jobError, cancel: cancelJob, reset: resetJob } =
    useJobPolling(activeJobId);

  // Load report detail
  const { data: report, isLoading } = useQuery({
    queryKey: ['report', reportId],
    queryFn: () => getReport(reportId),
  });

  // Initialize param values with resolved defaults when report loads
  useEffect(() => {
    if (report?.parameters) {
      const defaults: Record<string, string> = {};
      for (const p of report.parameters) {
        defaults[p.name] = p.resolvedDefault || '';
      }
      setParamValues(defaults);
    }
  }, [report]);

  const runMut = useMutation({
    mutationFn: (params: Record<string, string>) => runReport(reportId, params),
    onSuccess: (data: { jobId: string }) => setActiveJobId(data.jobId),
  });

  const handleRun = () => {
    resetJob();
    runMut.mutate(paramValues);
  };

  if (isLoading || !report) {
    return (
      <div className="px-4 py-6 text-center text-gray-400 text-sm">
        <Loader2 size={16} className="animate-spin inline mr-2" />
        Loading report…
      </div>
    );
  }

  return (
    <div className="border-t px-4 py-4 space-y-4 bg-gray-50/50">
      {/* Description */}
      {report.description && (
        <p className="text-sm text-gray-600">{report.description}</p>
      )}

      {/* Parameter form */}
      {report.parameters.length > 0 && (
        <div className="space-y-3">
          <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
            Parameters
          </h4>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {report.parameters.map((param) => (
              <ParamInput
                key={param.name}
                param={param}
                value={paramValues[param.name] || ''}
                options={report.selectOptions?.[param.name]}
                onChange={(v) =>
                  setParamValues((prev) => ({ ...prev, [param.name]: v }))
                }
              />
            ))}
          </div>
        </div>
      )}

      {/* Action buttons */}
      <div className="flex items-center gap-2 flex-wrap">
        {!isRunning ? (
          <button
            onClick={handleRun}
            disabled={runMut.isPending}
            className="flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700 disabled:opacity-50 transition-colors"
          >
            {runMut.isPending ? (
              <Loader2 size={14} className="animate-spin" />
            ) : (
              <Play size={14} />
            )}
            Run Report
          </button>
        ) : (
          <button
            onClick={cancelJob}
            className="flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded text-sm hover:bg-red-700 transition-colors"
          >
            <Square size={14} /> Cancel
          </button>
        )}
        <button
          onClick={() => setShowSql(!showSql)}
          className="flex items-center gap-2 border px-3 py-2 rounded text-sm hover:bg-gray-50 text-gray-600"
        >
          {showSql ? <EyeOff size={14} /> : <Eye size={14} />}
          {showSql ? 'Hide SQL' : 'View SQL'}
        </button>
        <button
          onClick={onDelete}
          className="flex items-center gap-1 border border-red-200 text-red-600 px-3 py-2 rounded text-sm hover:bg-red-50 ml-auto"
        >
          <Trash2 size={14} /> Delete
        </button>
      </div>

      {/* SQL preview */}
      {showSql && (
        <div>
          <SqlPreview sql={report.sqlTemplate} height="200px" />
          <p className="text-xs text-gray-400 mt-1">
            Parameters shown as :paramName — values are bound securely at execution time.
          </p>
        </div>
      )}

      {/* Job progress */}
      {isRunning && job && (
        <div className="p-4 bg-blue-50 border border-blue-200 rounded-lg">
          <div className="flex items-center gap-3">
            <Loader2 size={18} className="animate-spin text-blue-600" />
            <div>
              <div className="text-sm font-medium text-blue-800">
                {job.status === 'pending'
                  ? 'Queued — waiting for worker…'
                  : 'Running report…'}
              </div>
              <div className="text-xs text-blue-600 mt-0.5">
                Job {job.jobId.slice(0, 8)}…
                {job.startedAt && ` • Started ${job.startedAt}`}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Errors */}
      {runMut.isError && (
        <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
          Submit error: {String(runMut.error)}
        </div>
      )}
      {jobError && (
        <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
          Execution error: {jobError}
        </div>
      )}

      {/* Results */}
      {results && <ResultsTable data={results} />}
    </div>
  );
}

// ─── Parameter Input ────────────────────────────────────────────

function ParamInput({
  param,
  value,
  options,
  onChange,
}: {
  param: { name: string; type: string; label: string; placeholder?: string; description?: string; required: boolean };
  value: string;
  options?: { value: string; label: string }[];
  onChange: (v: string) => void;
}) {
  return (
    <div>
      <label className="block text-xs font-medium text-gray-600 mb-1">
        {param.label}
        {param.required && <span className="text-red-400 ml-0.5">*</span>}
      </label>

      {param.type === 'select' && options ? (
        <select
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="w-full border rounded px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
        >
          <option value="">— Any —</option>
          {options.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
      ) : param.type === 'boolean' ? (
        <select
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="w-full border rounded px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
        >
          <option value="">— Any —</option>
          <option value="true">Yes</option>
          <option value="false">No</option>
        </select>
      ) : param.type === 'list' ? (
        <textarea
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={param.placeholder || 'One value per line'}
          className="w-full border rounded px-3 py-2 text-sm h-20 resize-none focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none font-mono"
        />
      ) : (
        <input
          type={param.type === 'date' ? 'date' : param.type === 'number' ? 'number' : 'text'}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={param.placeholder}
          className="w-full border rounded px-3 py-2 text-sm focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
        />
      )}

      {param.description && (
        <p className="text-xs text-gray-400 mt-0.5">{param.description}</p>
      )}
    </div>
  );
}

// ─── AI Generate Modal ──────────────────────────────────────────

function GenerateModal({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: () => void;
}) {
  const [mode, setMode] = useState<'describe' | 'convert'>('describe');
  const [description, setDescription] = useState('');
  const [phpCode, setPhpCode] = useState('');
  const [preview, setPreview] = useState<ReportGenerateResponse | null>(null);

  const generateMut = useMutation({
    mutationFn: (desc: string) => generateReportTemplate(desc),
    onSuccess: (data: ReportGenerateResponse) => setPreview(data),
  });

  const convertMut = useMutation({
    mutationFn: (code: string) => convertReportFromPhp(code),
    onSuccess: (data: ReportGenerateResponse) => setPreview(data),
  });

  const saveMut = useMutation({
    mutationFn: (template: ReportGenerateResponse) => createReport(template),
    onSuccess: () => onCreated(),
  });

  return (
    <div className="fixed inset-0 bg-black/40 flex items-start justify-center z-50 p-4 overflow-y-auto">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-2xl mt-12 mb-12">
        {/* Header */}
        <div className="flex items-center gap-3 px-6 py-4 border-b">
          <Sparkles size={20} className="text-purple-600" />
          <h3 className="text-lg font-semibold">Create Report with AI</h3>
          <button onClick={onClose} className="ml-auto text-gray-400 hover:text-gray-600">
            <X size={20} />
          </button>
        </div>

        <div className="px-6 py-4 space-y-4">
          {/* Mode tabs */}
          {!preview && (
            <div className="flex border-b">
              <button
                onClick={() => setMode('describe')}
                className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                  mode === 'describe'
                    ? 'border-purple-600 text-purple-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                }`}
              >
                Describe
              </button>
              <button
                onClick={() => setMode('convert')}
                className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                  mode === 'convert'
                    ? 'border-purple-600 text-purple-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                }`}
              >
                Convert from PHP
              </button>
            </div>
          )}

          {/* Describe mode */}
          {!preview && mode === 'describe' && (
            <>
              <p className="text-sm text-gray-500">
                Describe the report you need and AI will generate a parameterized
                template with filters you can customize.
              </p>
              <textarea
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="e.g., Show all overdue loans grouped by patron group with borrower name, item title, due date, and days overdue. Let me filter by date range and location."
                className="w-full border rounded-lg px-4 py-3 text-sm h-28 resize-none focus:ring-2 focus:ring-purple-300 focus:border-purple-500 outline-none"
              />
              <div className="flex justify-end gap-2">
                <button
                  onClick={onClose}
                  className="px-4 py-2 text-sm border rounded-lg hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  onClick={() => generateMut.mutate(description)}
                  disabled={!description.trim() || generateMut.isPending}
                  className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50 transition-colors"
                >
                  {generateMut.isPending ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Send size={14} />
                  )}
                  {generateMut.isPending ? 'Generating…' : 'Generate'}
                </button>
              </div>
              {generateMut.isError && (
                <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                  {String(generateMut.error)}
                </div>
              )}
            </>
          )}

          {/* Convert from PHP mode */}
          {!preview && mode === 'convert' && (
            <>
              <p className="text-sm text-gray-500">
                Paste a Yii2 report model (PHP class) and AI will convert it into
                a parameterized report template.
              </p>
              <textarea
                value={phpCode}
                onChange={(e) => setPhpCode(e.target.value)}
                placeholder="Paste your Yii2 PHP report model code here…"
                className="w-full border rounded-lg px-4 py-3 text-sm h-48 resize-none focus:ring-2 focus:ring-purple-300 focus:border-purple-500 outline-none font-mono text-xs"
              />
              <div className="flex justify-end gap-2">
                <button
                  onClick={onClose}
                  className="px-4 py-2 text-sm border rounded-lg hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  onClick={() => convertMut.mutate(phpCode)}
                  disabled={!phpCode.trim() || convertMut.isPending}
                  className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50 transition-colors"
                >
                  {convertMut.isPending ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Send size={14} />
                  )}
                  {convertMut.isPending ? 'Converting…' : 'Convert'}
                </button>
              </div>
              {convertMut.isError && (
                <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                  {String(convertMut.error)}
                </div>
              )}
            </>
          )}

          {/* Preview generated template */}
          {preview && (
            <div className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">
                  Name
                </label>
                <input
                  value={preview.name}
                  onChange={(e) =>
                    setPreview({ ...preview, name: e.target.value })
                  }
                  className="w-full border rounded px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">
                  Description
                </label>
                <textarea
                  value={preview.description}
                  onChange={(e) =>
                    setPreview({ ...preview, description: e.target.value })
                  }
                  className="w-full border rounded px-3 py-2 text-sm h-16 resize-none"
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">
                    Category
                  </label>
                  <select
                    value={preview.category}
                    onChange={(e) =>
                      setPreview({
                        ...preview,
                        category: e.target.value as ReportCategory,
                      })
                    }
                    className="w-full border rounded px-3 py-2 text-sm bg-white"
                  >
                    {CATEGORIES.filter((c) => c.key !== 'all').map((c) => (
                      <option key={c.key} value={c.key}>
                        {c.label}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">
                    Row Limit
                  </label>
                  <input
                    type="number"
                    value={preview.defaultLimit}
                    onChange={(e) =>
                      setPreview({
                        ...preview,
                        defaultLimit: Number(e.target.value),
                      })
                    }
                    className="w-full border rounded px-3 py-2 text-sm"
                  />
                </div>
              </div>

              {/* Parameters preview */}
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">
                  Parameters ({preview.parameters.length})
                </label>
                <div className="border rounded p-3 space-y-2 bg-gray-50">
                  {preview.parameters.map((p, i) => (
                    <div
                      key={i}
                      className="flex items-center gap-3 text-xs"
                    >
                      <span className="font-mono text-folio-600">
                        :{p.name}
                      </span>
                      <span className="bg-white border rounded px-2 py-0.5">
                        {p.type}
                      </span>
                      <span className="text-gray-500">{p.label}</span>
                      {p.required && (
                        <span className="text-red-400">required</span>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              {/* SQL preview */}
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">
                  Generated SQL
                </label>
                <SqlPreview sql={preview.sqlTemplate} height="200px" />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t">
                <button
                  onClick={() => setPreview(null)}
                  className="px-4 py-2 text-sm border rounded-lg hover:bg-gray-50"
                >
                  Back
                </button>
                <button
                  onClick={() => saveMut.mutate(preview)}
                  disabled={saveMut.isPending}
                  className="flex items-center gap-2 bg-folio-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-folio-700 disabled:opacity-50 transition-colors"
                >
                  {saveMut.isPending ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Plus size={14} />
                  )}
                  {saveMut.isPending ? 'Saving…' : 'Save Report'}
                </button>
              </div>
              {saveMut.isError && (
                <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                  {String(saveMut.error)}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
