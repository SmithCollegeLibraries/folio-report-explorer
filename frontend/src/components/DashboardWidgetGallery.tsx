/**
 * DashboardWidgetGallery
 * Right-side slide-over panel listing all available widget templates.
 * Users can add/remove widgets; admins can also create, edit, and delete templates.
 */

import { useState, useEffect, useMemo } from 'react';
import {
  X, Plus, Minus, Loader2, AlertCircle, ChevronRight, ChevronDown,
  BarChart3, DollarSign, BookOpen, Package, FileBarChart, PieChart,
  TrendingUp, Map, Settings, Check, Pencil, Trash2, ChevronLeft,
} from 'lucide-react';
import {
  fetchDashboardWidgets,
  addDashboardWidget,
  removeDashboardWidget,
  createAdminWidget,
  updateAdminWidget,
  deleteAdminWidget,
  listReports,
} from '../api/client';
import type { DashboardWidgetTemplate, WidgetSetupParam, ReportSummary } from '../types';

// ── Icon lookup (must match the icon names stored in the DB) ──────────────────

const ICON_MAP: Record<string, React.ReactNode> = {
  DollarSign:  <DollarSign  size={20} />,
  BarChart3:   <BarChart3   size={20} />,
  BookOpen:    <BookOpen    size={20} />,
  Package:     <Package     size={20} />,
  FileBarChart:<FileBarChart size={20} />,
  PieChart:    <PieChart    size={20} />,
  TrendingUp:  <TrendingUp  size={20} />,
  Map:         <Map          size={20} />,
  Settings:    <Settings    size={20} />,
};

const ICON_OPTIONS = Object.keys(ICON_MAP);

function WidgetIcon({ name, className = '' }: { name: string; className?: string }) {
  const icon = ICON_MAP[name] ?? <BarChart3 size={20} />;
  return <span className={className}>{icon}</span>;
}

// ── Inline setup form for required report params ──────────────────────────────

interface SetupFormProps {
  params: WidgetSetupParam[];
  defaults: Record<string, string>;
  onSubmit: (values: Record<string, string>) => void;
  onCancel: () => void;
  busy: boolean;
}

function SetupForm({ params, defaults, onSubmit, onCancel, busy }: SetupFormProps) {
  const [values, setValues] = useState<Record<string, string>>(() => {
    const init: Record<string, string> = {};
    params.forEach((p) => { init[p.name] = defaults[p.name] ?? ''; });
    return init;
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(values);
  };

  return (
    <form onSubmit={handleSubmit} className="mt-3 space-y-2 bg-gray-50 border border-gray-200 rounded-lg p-3">
      {params.map((p) => (
        <div key={p.name}>
          <label className="block text-xs font-medium text-gray-600 mb-0.5">{p.label}</label>
          {p.type === 'select' && p.options.length > 0 ? (
            <select
              value={values[p.name] ?? ''}
              onChange={(e) => setValues((v) => ({ ...v, [p.name]: e.target.value }))}
              required
              className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white focus:outline-none focus:border-folio-500"
            >
              <option value="">— Select —</option>
              {p.options.map((opt, i) => {
                const val = String(opt.value ?? opt[Object.keys(opt)[0]] ?? '');
                const lbl = String(opt.label ?? opt[Object.keys(opt).find(k => k !== Object.keys(opt)[0]) ?? 0] ?? val);
                return <option key={i} value={val}>{lbl}</option>;
              })}
            </select>
          ) : (
            <input
              type="text"
              value={values[p.name] ?? ''}
              placeholder={p.placeholder}
              required={p.required}
              onChange={(e) => setValues((v) => ({ ...v, [p.name]: e.target.value }))}
              className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-folio-500"
            />
          )}
        </div>
      ))}

      <div className="flex items-center gap-2 pt-1">
        <button
          type="submit"
          disabled={busy}
          className="flex items-center gap-1.5 px-3 py-1.5 bg-folio-600 text-white text-xs rounded-lg hover:bg-folio-700 disabled:opacity-50 transition-colors"
        >
          {busy ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
          Add Widget
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="px-2 py-1.5 text-xs text-gray-400 hover:text-gray-600 transition-colors"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}

// ── Per-widget card ───────────────────────────────────────────────────────────

interface WidgetCardProps {
  widget: DashboardWidgetTemplate;
  onAdd: (id: number, params: Record<string, string>) => void;
  onRemove: (id: number) => void;
  busy: boolean;
  isAdmin: boolean;
  onEdit: (w: DashboardWidgetTemplate) => void;
  onDelete: (w: DashboardWidgetTemplate) => void;
}

function WidgetCard({ widget, onAdd, onRemove, busy, isAdmin, onEdit, onDelete }: WidgetCardProps) {
  const hasSetupParams = widget.setup_params && widget.setup_params.length > 0;
  const [showSetup, setShowSetup] = useState(false);

  const handleAddClick = () => {
    if (hasSetupParams) {
      setShowSetup(true);
    } else {
      onAdd(widget.id, {});
    }
  };

  const handleSetupSubmit = (vals: Record<string, string>) => {
    setShowSetup(false);
    onAdd(widget.id, vals);
  };

  return (
    <div className="flex flex-col rounded-lg border border-gray-200 bg-white px-3 py-2.5 gap-1">
      <div className="flex items-start gap-2.5">
        <div className="p-1.5 rounded-lg bg-folio-50 text-folio-600 flex-shrink-0 mt-0.5">
          <WidgetIcon name={widget.icon} />
        </div>

        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-gray-800 leading-snug">{widget.name}</p>
          {widget.description && (
            <p className="text-xs text-gray-400 mt-0.5 leading-snug">{widget.description}</p>
          )}
        </div>

        <div className="flex items-center gap-1 flex-shrink-0">
          {isAdmin && (
            <>
              <button
                title="Edit template"
                onClick={() => onEdit(widget)}
                className="p-1 text-gray-300 hover:text-folio-500 transition-colors rounded"
              >
                <Pencil size={13} />
              </button>
              <button
                title="Delete template"
                onClick={() => onDelete(widget)}
                className="p-1 text-gray-300 hover:text-red-500 transition-colors rounded"
              >
                <Trash2 size={13} />
              </button>
            </>
          )}
          {widget.is_added ? (
            <button
              onClick={() => onRemove(widget.id)}
              disabled={busy}
              title="Remove from dashboard"
              className="flex items-center gap-1 px-2 py-1 text-xs border border-red-200 text-red-500 rounded-lg hover:bg-red-50 disabled:opacity-50 transition-colors"
            >
              <Minus size={11} /> Remove
            </button>
          ) : (
            <button
              onClick={handleAddClick}
              disabled={busy}
              title={hasSetupParams ? 'Set up & add to dashboard' : 'Add to dashboard'}
              className="flex items-center gap-1 px-2 py-1 text-xs bg-folio-600 text-white rounded-lg hover:bg-folio-700 disabled:opacity-50 transition-colors"
            >
              <Plus size={11} /> Add
            </button>
          )}
        </div>
      </div>

      {showSetup && !widget.is_added && (
        <SetupForm
          params={widget.setup_params}
          defaults={widget.default_params ?? {}}
          onSubmit={handleSetupSubmit}
          onCancel={() => setShowSetup(false)}
          busy={busy}
        />
      )}
    </div>
  );
}

// ── Admin widget template form ────────────────────────────────────────────────

interface AdminFormProps {
  initial?: DashboardWidgetTemplate | null;
  reportList: ReportSummary[];
  onSave: (data: Record<string, unknown>) => void;
  onCancel: () => void;
  busy: boolean;
}

function AdminForm({ initial, reportList, onSave, onCancel, busy }: AdminFormProps) {
  const [name, setName]         = useState(initial?.name ?? '');
  const [desc, setDesc]         = useState(initial?.description ?? '');
  const [category, setCategory] = useState(initial?.category ?? 'other');
  const [icon, setIcon]         = useState(initial?.icon ?? 'BarChart3');
  const [widgetType, setType]   = useState<'report' | 'budget_monitor'>(initial?.widget_type ?? 'report');
  const [reportId, setReportId] = useState<string>(initial?.report_template_id ? String(initial.report_template_id) : '');
  const [sortOrder, setSort]    = useState(String(initial?.sort_order ?? '100'));
  const [defaultParamsJson, setDefaultParamsJson] = useState(
    initial?.default_params ? JSON.stringify(initial.default_params, null, 2) : '',
  );
  const [jsonError, setJsonError] = useState('');

  const validateAndSave = () => {
    let parsedParams: Record<string, string> | undefined;
    if (defaultParamsJson.trim()) {
      try {
        parsedParams = JSON.parse(defaultParamsJson);
      } catch {
        setJsonError('Invalid JSON');
        return;
      }
    }
    setJsonError('');
    onSave({
      name,
      description: desc,
      category,
      icon,
      widget_type: widgetType,
      report_template_id: reportId ? parseInt(reportId, 10) : null,
      sort_order: parseInt(sortOrder, 10) || 100,
      default_params: parsedParams ?? null,
    });
  };

  const allReports = useMemo(() => {
    return reportList.map((r) => ({ id: r.id, name: r.name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [reportList]);

  return (
    <div className="border border-folio-200 rounded-lg bg-folio-50 p-4 space-y-3">
      <h4 className="text-sm font-semibold text-folio-800">
        {initial ? 'Edit Widget Template' : 'New Widget Template'}
      </h4>

      <div className="space-y-2">
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-0.5">Name *</label>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-folio-500"
            placeholder="e.g. Budget by Format"
          />
        </div>

        <div>
          <label className="block text-xs font-medium text-gray-600 mb-0.5">Description</label>
          <input
            value={desc}
            onChange={(e) => setDesc(e.target.value)}
            className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-folio-500"
            placeholder="Brief description shown below the widget name"
          />
        </div>

        <div className="grid grid-cols-2 gap-2">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-0.5">Type</label>
            <select
              value={widgetType}
              onChange={(e) => setType(e.target.value as 'report' | 'budget_monitor')}
              className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white focus:outline-none focus:border-folio-500"
            >
              <option value="report">Report</option>
              <option value="budget_monitor">Budget Monitor</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-600 mb-0.5">Icon</label>
            <select
              value={icon}
              onChange={(e) => setIcon(e.target.value)}
              className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white focus:outline-none focus:border-folio-500"
            >
              {ICON_OPTIONS.map((i) => <option key={i} value={i}>{i}</option>)}
            </select>
          </div>
        </div>

        {widgetType === 'report' && (
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-0.5">Report Template</label>
            <select
              value={reportId}
              onChange={(e) => setReportId(e.target.value)}
              className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white focus:outline-none focus:border-folio-500"
            >
              <option value="">— Select a report —</option>
              {allReports.map((r) => <option key={r.id} value={r.id}>{r.id}. {r.name}</option>)}
            </select>
          </div>
        )}

        <div className="grid grid-cols-2 gap-2">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-0.5">Category</label>
            <input
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-folio-500"
              placeholder="e.g. acquisitions"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-0.5">Sort Order</label>
            <input
              type="number"
              value={sortOrder}
              onChange={(e) => setSort(e.target.value)}
              className="w-full text-xs border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-folio-500"
            />
          </div>
        </div>

        <div>
          <label className="block text-xs font-medium text-gray-600 mb-0.5">
            Default Params{' '}
            <span className="text-gray-400 font-normal">(JSON)</span>
          </label>
          <textarea
            value={defaultParamsJson}
            onChange={(e) => { setDefaultParamsJson(e.target.value); setJsonError(''); }}
            rows={4}
            placeholder={'{\n  "acq_unit_id": "uuid-here"\n}'}
            className={`w-full text-xs font-mono border rounded px-2 py-1.5 focus:outline-none resize-none ${
              jsonError ? 'border-red-400 focus:border-red-500' : 'border-gray-300 focus:border-folio-500'
            }`}
          />
          {jsonError && <p className="text-xs text-red-500 mt-0.5">{jsonError}</p>}
          <p className="text-xs text-gray-400 mt-0.5">
            Key-value pairs pre-filled when a user adds this widget. Macro strings like{' '}
            <code className="bg-gray-100 px-0.5 rounded">$fiscal_year_start</code> are resolved automatically.
          </p>
        </div>
      </div>

      <div className="flex items-center gap-2">
        <button
          onClick={validateAndSave}
          disabled={busy || !name.trim()}
          className="flex items-center gap-1.5 px-3 py-1.5 bg-folio-600 text-white text-xs rounded-lg hover:bg-folio-700 disabled:opacity-50 transition-colors"
        >
          {busy ? <Loader2 size={12} className="animate-spin" /> : <Check size={12} />}
          {initial ? 'Save Changes' : 'Create Widget'}
        </button>
        <button
          onClick={onCancel}
          className="px-2 py-1.5 text-xs text-gray-400 hover:text-gray-600 transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

// ── Main gallery panel ────────────────────────────────────────────────────────

export interface DashboardWidgetGalleryProps {
  isAdmin: boolean;
  onClose: () => void;
  onChanged: () => void;   // call when widgets are added/removed (triggers Dashboard re-fetch)
}

export default function DashboardWidgetGallery({ isAdmin, onClose, onChanged }: DashboardWidgetGalleryProps) {
  const [widgets, setWidgets]       = useState<DashboardWidgetTemplate[]>([]);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState<string | null>(null);
  const [busyId, setBusyId]         = useState<number | null>(null);

  // Category collapse state
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

  // Admin state
  const [adminOpen, setAdminOpen]       = useState(false);
  const [editingWidget, setEditingWidget] = useState<DashboardWidgetTemplate | null>(null);
  const [adminBusy, setAdminBusy]       = useState(false);
  const [reports, setReports]           = useState<ReportSummary[]>([]);

  const load = async () => {
    try {
      setLoading(true);
      setError(null);
      const list = await fetchDashboardWidgets();
      setWidgets(list);
    } catch {
      setError('Failed to load widget catalog');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  useEffect(() => {
    if (isAdmin && adminOpen && reports.length === 0) {
      listReports().then((grouped) => {
        const flat: ReportSummary[] = [];
        Object.values(grouped).forEach((arr) => flat.push(...arr));
        setReports(flat);
      }).catch(() => { /* non-critical */ });
    }
  }, [isAdmin, adminOpen, reports.length]);

  const handleAdd = async (id: number, params: Record<string, string>) => {
    setBusyId(id);
    try {
      await addDashboardWidget(id, params);
      setWidgets((prev) => prev.map((w) => w.id === id ? { ...w, is_added: true } : w));
      onChanged();
    } catch (e: unknown) {
      console.error('Failed to add widget', e);
    } finally {
      setBusyId(null);
    }
  };

  const handleRemove = async (id: number) => {
    setBusyId(id);
    try {
      await removeDashboardWidget(id);
      setWidgets((prev) => prev.map((w) => w.id === id ? { ...w, is_added: false } : w));
      onChanged();
    } catch (e: unknown) {
      console.error('Failed to remove widget', e);
    } finally {
      setBusyId(null);
    }
  };

  // Admin handlers
  const handleAdminSave = async (data: Record<string, unknown>) => {
    setAdminBusy(true);
    try {
      if (editingWidget) {
        await updateAdminWidget(editingWidget.id, data as never);
      } else {
        await createAdminWidget(data as never);
      }
      setEditingWidget(null);
      setAdminOpen(false);
      await load();
      onChanged();
    } catch {
      /* surface error inline later if needed */
    } finally {
      setAdminBusy(false);
    }
  };

  const handleAdminDelete = async (w: DashboardWidgetTemplate) => {
    if (!confirm(`Disable the "${w.name}" widget template?`)) return;
    setAdminBusy(true);
    try {
      await deleteAdminWidget(w.id);
      await load();
      onChanged();
    } catch { /* ignore */ } finally {
      setAdminBusy(false);
    }
  };

  // Group widgets by category
  const groups = useMemo(() => {
    const map: Record<string, DashboardWidgetTemplate[]> = {};
    widgets.forEach((w) => {
      const cat = w.category || 'other';
      if (!map[cat]) map[cat] = [];
      map[cat].push(w);
    });
    return map;
  }, [widgets]);

  const toggleCollapsed = (cat: string) =>
    setCollapsed((prev) => ({ ...prev, [cat]: !prev[cat] }));

  const addedCount = widgets.filter((w) => w.is_added).length;

  return (
    <>
      {/* Backdrop */}
      <div
        className="fixed inset-0 z-40 bg-black/20"
        onClick={onClose}
        aria-hidden="true"
      />

      {/* Slide-over panel */}
      <div className="fixed top-0 right-0 h-full z-50 w-full max-w-sm bg-white shadow-2xl flex flex-col border-l border-gray-200">
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-4 border-b bg-white">
          <div>
            <h2 className="font-semibold text-gray-800">Add Widgets</h2>
            <p className="text-xs text-gray-400 mt-0.5">
              {addedCount} of {widgets.length} added to your dashboard
            </p>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors"
          >
            <X size={18} />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-4 py-3 space-y-4">
          {loading && (
            <div className="flex items-center justify-center py-12 gap-2 text-gray-400">
              <Loader2 size={18} className="animate-spin text-folio-500" />
              <span className="text-sm">Loading widgets…</span>
            </div>
          )}

          {error && (
            <div className="flex items-center gap-2 text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">
              <AlertCircle size={16} />
              {error}
            </div>
          )}

          {!loading && !error && Object.entries(groups).map(([cat, items]) => (
            <div key={cat}>
              <button
                onClick={() => toggleCollapsed(cat)}
                className="flex items-center gap-2 w-full text-left mb-2 group"
              >
                {collapsed[cat]
                  ? <ChevronRight size={14} className="text-gray-400 group-hover:text-folio-600" />
                  : <ChevronDown  size={14} className="text-gray-400 group-hover:text-folio-600" />
                }
                <span className="text-xs font-semibold uppercase tracking-wide text-gray-500 group-hover:text-folio-600">
                  {cat}
                </span>
                <span className="text-xs text-gray-400 ml-auto">
                  {items.filter((w) => w.is_added).length}/{items.length}
                </span>
              </button>

              {!collapsed[cat] && (
                <div className="space-y-2">
                  {items.map((w) => (
                    <WidgetCard
                      key={w.id}
                      widget={w}
                      onAdd={handleAdd}
                      onRemove={handleRemove}
                      busy={busyId === w.id}
                      isAdmin={isAdmin}
                      onEdit={(ww) => { setEditingWidget(ww); setAdminOpen(true); }}
                      onDelete={handleAdminDelete}
                    />
                  ))}
                </div>
              )}
            </div>
          ))}

          {/* Admin section */}
          {isAdmin && (
            <div className="pt-2 border-t border-dashed border-gray-200">
              {adminOpen ? (
                <AdminForm
                  initial={editingWidget}
                  reportList={reports}
                  onSave={handleAdminSave}
                  onCancel={() => { setAdminOpen(false); setEditingWidget(null); }}
                  busy={adminBusy}
                />
              ) : (
                <button
                  onClick={() => { setEditingWidget(null); setAdminOpen(true); }}
                  className="flex items-center gap-2 w-full px-3 py-2 text-xs border border-dashed border-folio-300 text-folio-600 rounded-lg hover:bg-folio-50 transition-colors"
                >
                  <Plus size={13} />
                  New Widget Template
                </button>
              )}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-4 py-3 border-t bg-gray-50">
          <button
            onClick={onClose}
            className="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 transition-colors"
          >
            <ChevronLeft size={14} /> Done
          </button>
        </div>
      </div>
    </>
  );
}
