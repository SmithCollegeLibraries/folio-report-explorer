import { useState, useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchNl2SqlDashboard, fetchSettings, saveSettings, testSettings } from '../api/client';
import type { AppSettings, Nl2SqlDashboardResponse, SettingsTestResponse } from '../types';
import {
  Settings as SettingsIcon,
  Database,
  Sparkles,
  Activity,
  AlertTriangle,
  BarChart3,
  CheckCircle2,
  XCircle,
  Loader2,
  Eye,
  EyeOff,
  RefreshCw,
} from 'lucide-react';

type SettingsData = Pick<
  AppSettings,
  | 'pg_host'
  | 'pg_port'
  | 'pg_db'
  | 'pg_user'
  | 'pg_pass'
  | 'pg_sslmode'
  | 'ai_provider'
  | 'gemini_api_key'
  | 'gemini_model'
  | 'openai_api_key'
  | 'openai_model'
>;

interface ConnectionResult {
  connected: boolean;
  error?: string;
}

interface PgConnectionResult extends ConnectionResult {
  version?: string;
}

interface GeminiConnectionResult extends ConnectionResult {
  model?: string;
  displayName?: string;
}

interface OpenAiConnectionResult extends ConnectionResult {
  model?: string;
}

const EMPTY: SettingsData = {
  pg_host: '',
  pg_port: '5432',
  pg_db: 'ldplite',
  pg_user: '',
  pg_pass: '',
  pg_sslmode: 'require',
  ai_provider: 'gemini',
  gemini_api_key: '',
  gemini_model: 'gemini-2.5-flash',
  openai_api_key: '',
  openai_model: 'gpt-4.1-mini',
};

const SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

export default function Settings() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<SettingsData>(EMPTY);
  const [showPgPass, setShowPgPass] = useState(false);
  const [showGeminiKey, setShowGeminiKey] = useState(false);
  const [showOpenAiKey, setShowOpenAiKey] = useState(false);
  const [pgTestResult, setPgTestResult] = useState<PgConnectionResult | null>(null);
  const [geminiTestResult, setGeminiTestResult] = useState<GeminiConnectionResult | null>(null);
  const [openAiTestResult, setOpenAiTestResult] = useState<OpenAiConnectionResult | null>(null);
  const [saved, setSaved] = useState(false);

  // Load current settings
  const { data: current, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: fetchSettings,
  });
  const {
    data: nl2sqlDashboard,
    isLoading: dashboardLoading,
    isFetching: dashboardFetching,
    error: dashboardError,
    refetch: refetchDashboard,
  } = useQuery({
    queryKey: ['settings', 'nl2sql-dashboard'],
    queryFn: fetchNl2SqlDashboard,
  });

  // Populate form when settings load
  useEffect(() => {
    if (current) {
      setForm((prev) => ({
        ...prev,
        pg_host: current.pg_host || '',
        pg_port: current.pg_port || '5432',
        pg_db: current.pg_db || 'ldplite',
        pg_user: current.pg_user || '',
        pg_pass: '', // don't populate masked passwords
        pg_sslmode: current.pg_sslmode || 'require',
        ai_provider: current.ai_provider === 'openai' ? 'openai' : 'gemini',
        gemini_api_key: '', // don't populate masked keys
        gemini_model: current.gemini_model || 'gemini-2.5-flash',
        openai_api_key: '', // don't populate masked keys
        openai_model: current.openai_model || 'gpt-4.1-mini',
      }));
    }
  }, [current]);

  const saveMut = useMutation({
    mutationFn: (data: Partial<SettingsData>) => saveSettings(data),
    onSuccess: () => {
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      queryClient.invalidateQueries({ queryKey: ['health'] });
    },
  });

  const testPgMut = useMutation({
    mutationFn: () =>
      testSettings({
        test_postgres: true,
        pg_host: form.pg_host,
        pg_port: form.pg_port,
        pg_db: form.pg_db,
        pg_user: form.pg_user,
        pg_pass: form.pg_pass || undefined,
        pg_sslmode: form.pg_sslmode,
      }),
    onSuccess: (data: SettingsTestResponse) => setPgTestResult(data.postgres ?? null),
  });

  const testGeminiMut = useMutation({
    mutationFn: () =>
      testSettings({
        test_gemini: true,
        gemini_api_key: form.gemini_api_key || undefined,
        gemini_model: form.gemini_model,
      }),
    onSuccess: (data: SettingsTestResponse) => setGeminiTestResult(data.gemini ?? null),
  });

  const testOpenAiMut = useMutation({
    mutationFn: () =>
      testSettings({
        test_openai: true,
        openai_api_key: form.openai_api_key || undefined,
        openai_model: form.openai_model,
      }),
    onSuccess: (data: SettingsTestResponse) => setOpenAiTestResult(data.openai ?? null),
  });

  const handleSave = () => {
    // Only send non-empty fields to avoid overwriting with blanks
    const payload: Partial<SettingsData> = {};
    if (form.pg_host) payload.pg_host = form.pg_host;
    if (form.pg_port) payload.pg_port = form.pg_port;
    if (form.pg_db) payload.pg_db = form.pg_db;
    if (form.pg_user) payload.pg_user = form.pg_user;
    if (form.pg_pass) payload.pg_pass = form.pg_pass;
    if (form.pg_sslmode) payload.pg_sslmode = form.pg_sslmode;
    if (form.ai_provider) payload.ai_provider = form.ai_provider;
    if (form.gemini_api_key) payload.gemini_api_key = form.gemini_api_key;
    if (form.gemini_model) payload.gemini_model = form.gemini_model;
    if (form.openai_api_key) payload.openai_api_key = form.openai_api_key;
    if (form.openai_model) payload.openai_model = form.openai_model;
    saveMut.mutate(payload);
  };

  const update = (field: keyof SettingsData, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    setPgTestResult(null);
    setGeminiTestResult(null);
    setOpenAiTestResult(null);
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96 text-gray-500">
        Loading settings…
      </div>
    );
  }

  const hasPgSaved = !!(current?.pg_host && current.pg_pass);
  const hasGeminiSaved = !!current?.gemini_api_key;
  const hasOpenAiSaved = !!current?.openai_api_key;

  return (
    <div className="max-w-6xl mx-auto p-4 sm:p-6">
      <div className="flex items-center gap-3 mb-6">
        <SettingsIcon size={24} className="text-folio-600" />
        <div>
          <h2 className="text-xl font-semibold">Setup</h2>
          <p className="text-sm text-gray-500">
            Configure your database connection and AI settings. Saved to the
            backend for this dev session.
          </p>
        </div>
      </div>

      <section className="mb-8">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
          <div className="flex items-center gap-2">
            <Activity size={18} className="text-folio-600" />
            <div>
              <h3 className="text-lg font-semibold">NL2SQL Shadow Ops</h3>
              <p className="text-sm text-gray-500">
                Compact cohort health for shadow comparisons, failure families, and replay deltas.
              </p>
            </div>
          </div>
          <button
            onClick={() => refetchDashboard()}
            disabled={dashboardFetching}
            type="button"
            className="inline-flex items-center gap-2 border px-4 py-2 rounded text-sm hover:bg-gray-50 disabled:opacity-50"
          >
            {dashboardFetching ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
            Refresh Dashboard
          </button>
        </div>

        {dashboardLoading ? (
          <div className="bg-white border rounded-lg p-5 text-sm text-gray-500 flex items-center gap-2">
            <Loader2 size={16} className="animate-spin" /> Loading NL2SQL operator dashboard…
          </div>
        ) : dashboardError ? (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-700">
            Failed to load the NL2SQL operator dashboard.
          </div>
        ) : nl2sqlDashboard ? (
          <Nl2SqlDashboardPanel dashboard={nl2sqlDashboard} />
        ) : null}
      </section>

      {/* ── PostgreSQL ────────────────────────── */}
      <section className="mb-8">
        <div className="flex items-center gap-2 mb-4">
          <Database size={18} className="text-blue-600" />
          <h3 className="text-lg font-semibold">PostgreSQL Connection</h3>
          {hasPgSaved && (
            <span className="ml-auto flex items-center gap-1 text-xs text-green-600">
              <CheckCircle2 size={14} /> Configured
            </span>
          )}
        </div>

        <div className="bg-white border rounded-lg p-5 space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div className="col-span-2">
              <Label>Host</Label>
              <Input
                value={form.pg_host}
                onChange={(v) => update('pg_host', v)}
                placeholder="db-host.example.com"
              />
            </div>
            <div>
              <Label>Port</Label>
              <Input
                value={form.pg_port}
                onChange={(v) => update('pg_port', v)}
                placeholder="5432"
              />
            </div>
          </div>

          <div>
            <Label>Database</Label>
            <Input
              value={form.pg_db}
              onChange={(v) => update('pg_db', v)}
              placeholder="ldplite"
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label>Username</Label>
              <Input
                value={form.pg_user}
                onChange={(v) => update('pg_user', v)}
                placeholder="readonly_user"
              />
            </div>
            <div>
              <Label>Password</Label>
              <div className="relative">
                <Input
                  value={form.pg_pass}
                  onChange={(v) => update('pg_pass', v)}
                  placeholder={hasPgSaved ? '(unchanged)' : 'Enter password'}
                  type={showPgPass ? 'text' : 'password'}
                />
                <button
                  onClick={() => setShowPgPass(!showPgPass)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                  type="button"
                >
                  {showPgPass ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </div>
            </div>
          </div>

          <div>
            <Label>SSL Mode</Label>
            <select
              value={form.pg_sslmode}
              onChange={(e) => update('pg_sslmode', e.target.value)}
              className="w-full border rounded px-3 py-2 text-sm bg-white"
            >
              {SSL_MODES.map((m) => (
                <option key={m} value={m}>
                  {m}
                </option>
              ))}
            </select>
          </div>

          {/* Test result */}
          {pgTestResult && (
            <TestResult
              connected={pgTestResult.connected}
              success={pgTestResult.version ? `Connected! ${pgTestResult.version}` : 'Connected!'}
              error={pgTestResult.error}
            />
          )}

          <div className="flex gap-2">
            <button
              onClick={() => testPgMut.mutate()}
              disabled={!form.pg_host || !form.pg_user || testPgMut.isPending}
              className="flex items-center gap-2 border px-4 py-2 rounded text-sm hover:bg-gray-50 disabled:opacity-50"
            >
              {testPgMut.isPending ? (
                <Loader2 size={14} className="animate-spin" />
              ) : (
                <RefreshCw size={14} />
              )}
              Test Connection
            </button>
          </div>
        </div>
      </section>

      {/* ── Gemini AI ──────────────────────────── */}
      <section className="mb-8">
        <div className="flex items-center gap-2 mb-4">
          <Sparkles size={18} className="text-purple-600" />
          <h3 className="text-lg font-semibold">AI Provider</h3>
        </div>

        <div className="bg-white border rounded-lg p-5 space-y-4">
          <div>
            <Label>Active Provider</Label>
            <select
              value={form.ai_provider}
              onChange={(e) => update('ai_provider', e.target.value)}
              className="w-full border rounded px-3 py-2 text-sm bg-white"
            >
              <option value="gemini">Gemini</option>
              <option value="openai">OpenAI</option>
            </select>
            <p className="text-xs text-gray-400 mt-1">
              Ask AI uses this provider after you save settings.
            </p>
          </div>
        </div>
      </section>

      {/* ── Gemini AI ──────────────────────────── */}
      <section className="mb-8">
        <div className="flex items-center gap-2 mb-4">
          <Sparkles size={18} className="text-purple-600" />
          <h3 className="text-lg font-semibold">Gemini AI</h3>
          {hasGeminiSaved && (
            <span className="ml-auto flex items-center gap-1 text-xs text-green-600">
              <CheckCircle2 size={14} /> Configured
            </span>
          )}
        </div>

        <div className="bg-white border rounded-lg p-5 space-y-4">
          <div>
            <Label>API Key</Label>
            <div className="relative">
              <Input
                value={form.gemini_api_key}
                onChange={(v) => update('gemini_api_key', v)}
                placeholder={hasGeminiSaved ? '(unchanged)' : 'Enter Gemini API key'}
                type={showGeminiKey ? 'text' : 'password'}
              />
              <button
                onClick={() => setShowGeminiKey(!showGeminiKey)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                type="button"
              >
                {showGeminiKey ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            <p className="text-xs text-gray-400 mt-1">
              Get a free key at{' '}
              <a
                href="https://aistudio.google.com/apikey"
                target="_blank"
                rel="noopener noreferrer"
                className="text-folio-600 hover:underline"
              >
                aistudio.google.com/apikey
              </a>
            </p>
          </div>

          <div>
            <Label>Model</Label>
            <Input
              value={form.gemini_model}
              onChange={(v) => update('gemini_model', v)}
              placeholder="gemini-2.5-flash"
            />
          </div>

          {/* Test result */}
          {geminiTestResult && (
            <TestResult
              connected={geminiTestResult.connected}
              success={
                geminiTestResult.displayName
                  ? `Connected! Model: ${geminiTestResult.displayName}`
                  : 'Connected!'
              }
              error={geminiTestResult.error}
            />
          )}

          <div className="flex gap-2">
            <button
              onClick={() => testGeminiMut.mutate()}
              disabled={
                (!form.gemini_api_key && !hasGeminiSaved) ||
                testGeminiMut.isPending
              }
              className="flex items-center gap-2 border px-4 py-2 rounded text-sm hover:bg-gray-50 disabled:opacity-50"
            >
              {testGeminiMut.isPending ? (
                <Loader2 size={14} className="animate-spin" />
              ) : (
                <RefreshCw size={14} />
              )}
              Test API Key
            </button>
          </div>
        </div>
      </section>

      {/* ── OpenAI ─────────────────────────────── */}
      <section className="mb-8">
        <div className="flex items-center gap-2 mb-4">
          <Sparkles size={18} className="text-emerald-600" />
          <h3 className="text-lg font-semibold">OpenAI</h3>
          {hasOpenAiSaved && (
            <span className="ml-auto flex items-center gap-1 text-xs text-green-600">
              <CheckCircle2 size={14} /> Configured
            </span>
          )}
        </div>

        <div className="bg-white border rounded-lg p-5 space-y-4">
          <div>
            <Label>API Key</Label>
            <div className="relative">
              <Input
                value={form.openai_api_key}
                onChange={(v) => update('openai_api_key', v)}
                placeholder={hasOpenAiSaved ? '(unchanged)' : 'Enter OpenAI API key'}
                type={showOpenAiKey ? 'text' : 'password'}
              />
              <button
                onClick={() => setShowOpenAiKey(!showOpenAiKey)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                type="button"
              >
                {showOpenAiKey ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            <p className="text-xs text-gray-400 mt-1">
              Generate an API key in the OpenAI dashboard.
            </p>
          </div>

          <div>
            <Label>Model</Label>
            <Input
              value={form.openai_model}
              onChange={(v) => update('openai_model', v)}
              placeholder="gpt-4.1-mini"
            />
          </div>

          {openAiTestResult && (
            <TestResult
              connected={openAiTestResult.connected}
              success={openAiTestResult.model ? `Connected! Model: ${openAiTestResult.model}` : 'Connected!'}
              error={openAiTestResult.error}
            />
          )}

          <div className="flex gap-2">
            <button
              onClick={() => testOpenAiMut.mutate()}
              disabled={
                (!form.openai_api_key && !hasOpenAiSaved) ||
                testOpenAiMut.isPending
              }
              className="flex items-center gap-2 border px-4 py-2 rounded text-sm hover:bg-gray-50 disabled:opacity-50"
            >
              {testOpenAiMut.isPending ? (
                <Loader2 size={14} className="animate-spin" />
              ) : (
                <RefreshCw size={14} />
              )}
              Test API Key
            </button>
          </div>
        </div>
      </section>

      {/* ── Save button ───────────────────────── */}
      <div className="flex items-center gap-3">
        <button
          onClick={handleSave}
          disabled={saveMut.isPending}
          className="bg-folio-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-folio-700 disabled:opacity-50 transition-colors"
        >
          {saveMut.isPending ? 'Saving…' : 'Save Settings'}
        </button>

        {saved && (
          <span className="flex items-center gap-1 text-green-600 text-sm animate-pulse">
            <CheckCircle2 size={16} /> Settings saved!
          </span>
        )}

        {saveMut.isError && (
          <span className="text-red-600 text-sm">
            Error: {String(saveMut.error)}
          </span>
        )}
      </div>
    </div>
  );
}

function Nl2SqlDashboardPanel({ dashboard }: { dashboard: Nl2SqlDashboardResponse }) {
  const shadowEnabled = dashboard.cohort.shadowMode;
  const replayGateMet = dashboard.replay.gateMet;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <MetricCard
          icon={<Activity size={16} className="text-folio-700" />}
          label="Shadow compares"
          value={String(dashboard.shadow.compareCount)}
          detail={`${dashboard.shadow.mismatchCount} mismatches in the last ${dashboard.shadow.windowDays} days`}
          tone="folio"
        />
        <MetricCard
          icon={<AlertTriangle size={16} className="text-amber-700" />}
          label="Shadow errors"
          value={String(dashboard.shadow.errorCount)}
          detail={`${dashboard.shadow.dataSourceMismatchCount} data-source divergences`}
          tone="amber"
        />
        <MetricCard
          icon={<BarChart3 size={16} className="text-sky-700" />}
          label="Failure families"
          value={String(dashboard.failureReview.familyCount)}
          detail={dashboard.failureReview.available
            ? `${dashboard.failureReview.historyFailureCount} recent history failures classified`
            : 'No failure-review artifact yet'}
          tone="sky"
        />
        <MetricCard
          icon={<CheckCircle2 size={16} className={replayGateMet ? 'text-green-700' : 'text-rose-700'} />}
          label="Replay gate"
          value={replayGateMet ? 'Passing' : 'Needs review'}
          detail={dashboard.replay.available
            ? `${dashboard.replay.failCount} failed prompts, ${dashboard.replay.failedGateKeys.length} failed checks`
            : 'No replay artifact yet'}
          tone={replayGateMet ? 'green' : 'rose'}
        />
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <section className="bg-white border rounded-lg p-5 space-y-4">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h4 className="font-semibold text-gray-900">Cohort status</h4>
              <p className="text-sm text-gray-500">Current NL2SQL rollout settings for shadow traffic.</p>
            </div>
            <StatusChip tone={shadowEnabled ? 'green' : 'slate'}>
              {shadowEnabled ? 'Shadow enabled' : 'Shadow disabled'}
            </StatusChip>
          </div>

          <div className="grid grid-cols-2 gap-3 text-sm">
            <SummaryCell label="Primary mode" value={dashboard.cohort.primaryMode} />
            <SummaryCell label="Intent mode" value={dashboard.cohort.intentMode ? 'On' : 'Off'} />
            <SummaryCell label="Sample percent" value={`${dashboard.cohort.shadowSamplePercent}%`} />
            <SummaryCell label="Allowlist" value={dashboard.cohort.shadowUsers || 'None'} />
          </div>

          <div className="pt-1 border-t border-gray-100">
            <div className="flex items-center justify-between text-sm mb-2">
              <span className="text-gray-600">SQL hash match rate</span>
              <span className="font-medium text-gray-900">{formatPercent(dashboard.shadow.matchRate)}</span>
            </div>
            <div className="flex items-center justify-between text-sm mb-2">
              <span className="text-gray-600">SQL hash mismatch rate</span>
              <span className="font-medium text-gray-900">{formatPercent(dashboard.shadow.mismatchRate)}</span>
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-600">Unknown comparisons</span>
              <span className="font-medium text-gray-900">{dashboard.shadow.unknownCount}</span>
            </div>
          </div>

          <div>
            <h5 className="text-sm font-medium text-gray-700 mb-2">Top shadow errors</h5>
            {dashboard.shadow.topErrors.length > 0 ? (
              <div className="space-y-2">
                {dashboard.shadow.topErrors.map((item) => (
                  <div key={item.message} className="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div className="text-sm text-gray-900 leading-5">{item.message}</div>
                    <div className="text-xs text-gray-500 mt-1">Count: {item.count}</div>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-gray-500">No shadow errors in the current window.</p>
            )}
          </div>
        </section>

        <section className="bg-white border rounded-lg p-5 space-y-4">
          <div>
            <h4 className="font-semibold text-gray-900">Failure families</h4>
            <p className="text-sm text-gray-500">Latest offline review of telemetry, replay, shadow, and history evidence.</p>
          </div>

          {dashboard.failureReview.available ? (
            <>
              <div className="grid grid-cols-2 gap-3 text-sm">
                <SummaryCell label="Telemetry events" value={String(dashboard.failureReview.telemetryEventCount)} />
                <SummaryCell label="Replay failures" value={String(dashboard.failureReview.replayFailureCount)} />
                <SummaryCell label="History failures" value={String(dashboard.failureReview.historyFailureCount)} />
                <SummaryCell label="Window" value={`${dashboard.failureReview.windowDays ?? 0} days`} />
              </div>

              <div className="space-y-2">
                {dashboard.failureReview.topFamilies.map((family) => (
                  <div key={family.key} className="rounded-lg border border-gray-100 bg-gray-50 p-3">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <div className="font-medium text-gray-900 text-sm">{family.title}</div>
                        <div className="text-xs text-gray-500 mt-1">{family.category} • {family.severity}</div>
                      </div>
                      <StatusChip tone={family.severity === 'high' ? 'rose' : family.severity === 'medium' ? 'amber' : 'slate'}>
                        {family.occurrenceCount}
                      </StatusChip>
                    </div>
                    <p className="text-xs text-gray-600 mt-2 leading-5">{family.action}</p>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <p className="text-sm text-gray-500">No failure-review artifact is available yet.</p>
          )}
        </section>

        <section className="bg-white border rounded-lg p-5 space-y-4">
          <div>
            <h4 className="font-semibold text-gray-900">Replay deltas</h4>
            <p className="text-sm text-gray-500">Latest acceptance gate outcome and prompt-budget pressure.</p>
          </div>

          {dashboard.replay.available ? (
            <>
              <div className="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                <div>
                  <div className="text-sm font-medium text-gray-900">Latest replay capture</div>
                  <div className="text-xs text-gray-500">{dashboard.replay.capturedAt || 'Unknown timestamp'}</div>
                </div>
                <StatusChip tone={replayGateMet ? 'green' : 'rose'}>
                  {replayGateMet ? 'Gate met' : 'Gate failed'}
                </StatusChip>
              </div>

              <div className="grid grid-cols-2 gap-3 text-sm">
                <SummaryCell label="Pass rate" value={dashboard.replay.passRate == null ? 'n/a' : formatPercent(dashboard.replay.passRate)} />
                <SummaryCell label="Regressions" value={String(dashboard.replay.regressionsOnBaselineSuccess)} />
                <SummaryCell label="Prompt quality" value={dashboard.replay.promptQualityFailureCount == null ? 'n/a' : String(dashboard.replay.promptQualityFailureCount)} />
                <SummaryCell label="Over budget" value={dashboard.replay.overBudgetPromptCount == null ? 'n/a' : String(dashboard.replay.overBudgetPromptCount)} />
                <SummaryCell label="New semantic families" value={dashboard.replay.newSemanticFamilyCount == null ? 'n/a' : String(dashboard.replay.newSemanticFamilyCount)} />
                <SummaryCell label="Max prompt delta" value={dashboard.replay.maxPromptSizeIncreaseBytes == null ? 'n/a' : `${dashboard.replay.maxPromptSizeIncreaseBytes.toLocaleString()} bytes`} />
              </div>

              <div>
                <h5 className="text-sm font-medium text-gray-700 mb-2">Failed gate keys</h5>
                {dashboard.replay.failedGateKeys.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {dashboard.replay.failedGateKeys.map((key) => (
                      <StatusChip key={key} tone="rose">{key}</StatusChip>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-gray-500">No failed acceptance gates recorded.</p>
                )}
              </div>

              <div>
                <h5 className="text-sm font-medium text-gray-700 mb-2">Recent mismatch evidence</h5>
                {dashboard.shadow.recentMismatches.length > 0 ? (
                  <div className="space-y-2">
                    {dashboard.shadow.recentMismatches.map((item) => (
                      <div key={`${item.promptFingerprint}-${item.timestamp}`} className="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm">
                        <div className="font-medium text-gray-900">{item.promptFingerprint}</div>
                        <div className="text-xs text-gray-500 mt-1">{item.primaryRoute} → {item.shadowRoute}</div>
                        <div className="text-xs text-gray-500 mt-1">{item.primaryDataSource} vs {item.shadowDataSource}</div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-gray-500">No recent shadow mismatches in the current window.</p>
                )}
              </div>
            </>
          ) : (
            <p className="text-sm text-gray-500">No replay artifact is available yet.</p>
          )}
        </section>
      </div>

      <section className="bg-white border rounded-lg p-5">
        <div className="flex items-center gap-2 mb-3">
          <AlertTriangle size={16} className="text-amber-600" />
          <h4 className="font-semibold text-gray-900">Recent failed history jobs</h4>
        </div>

        {dashboard.history.recentFailedJobs.length > 0 ? (
          <div className="space-y-2">
            {dashboard.history.recentFailedJobs.map((job) => (
              <div key={job.jobId} className="rounded-lg border border-gray-100 bg-gray-50 p-3">
                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                  <div className="font-medium text-sm text-gray-900">{job.name || job.jobId}</div>
                  <div className="text-xs text-gray-500">{job.source || 'unknown source'} • {job.completedAt || 'unknown time'}</div>
                </div>
                <div className="text-sm text-gray-600 mt-2 leading-5">{job.errorMessage || 'No error message captured.'}</div>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-500">No recent failed history jobs were found.</p>
        )}
      </section>
    </div>
  );
}

function MetricCard({
  icon,
  label,
  value,
  detail,
  tone,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  detail: string;
  tone: 'folio' | 'amber' | 'sky' | 'green' | 'rose';
}) {
  const toneClass = {
    folio: 'bg-folio-50 border-folio-100',
    amber: 'bg-amber-50 border-amber-100',
    sky: 'bg-sky-50 border-sky-100',
    green: 'bg-green-50 border-green-100',
    rose: 'bg-rose-50 border-rose-100',
  }[tone];

  return (
    <div className={`rounded-lg border p-4 ${toneClass}`}>
      <div className="flex items-center justify-between gap-3 mb-3">
        <div className="text-sm font-medium text-gray-700">{label}</div>
        {icon}
      </div>
      <div className="text-2xl font-semibold text-gray-900">{value}</div>
      <div className="text-sm text-gray-600 mt-1 leading-5">{detail}</div>
    </div>
  );
}

function SummaryCell({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-gray-100 bg-gray-50 p-3">
      <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
      <div className="text-sm font-medium text-gray-900 mt-1 break-words">{value}</div>
    </div>
  );
}

function StatusChip({ children, tone }: { children: React.ReactNode; tone: 'green' | 'rose' | 'amber' | 'slate' }) {
  const toneClass = {
    green: 'bg-green-100 text-green-800',
    rose: 'bg-rose-100 text-rose-800',
    amber: 'bg-amber-100 text-amber-800',
    slate: 'bg-slate-100 text-slate-700',
  }[tone];

  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${toneClass}`}>
      {children}
    </span>
  );
}

function formatPercent(value: number) {
  return `${value.toFixed(1)}%`;
}

// ─── Helpers ─────────────────────────────────────────────────────

function Label({ children }: { children: React.ReactNode }) {
  return (
    <label className="block text-xs font-medium text-gray-600 mb-1">
      {children}
    </label>
  );
}

function Input({
  value,
  onChange,
  placeholder,
  type = 'text',
}: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  type?: string;
}) {
  return (
    <input
      type={type}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="w-full border rounded px-3 py-2 text-sm focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
    />
  );
}

function TestResult({
  connected,
  success,
  error,
}: {
  connected: boolean;
  success: string;
  error?: string;
}) {
  return (
    <div
      className={`flex items-start gap-2 p-3 rounded text-sm ${
        connected
          ? 'bg-green-50 border border-green-200 text-green-700'
          : 'bg-red-50 border border-red-200 text-red-700'
      }`}
    >
      {connected ? (
        <CheckCircle2 size={16} className="mt-0.5 flex-shrink-0" />
      ) : (
        <XCircle size={16} className="mt-0.5 flex-shrink-0" />
      )}
      <span>{connected ? success : error}</span>
    </div>
  );
}
