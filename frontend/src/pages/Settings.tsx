import { useState, useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchReferenceCacheCandidates, fetchReferenceCacheStatus, fetchSettings, refreshReferenceCacheTable, reviewReferenceCacheCandidate, saveSettings, testSettings } from '../api/client';
import type { AppSettings, SettingsTestResponse } from '../types';
import {
  Settings as SettingsIcon,
  Database,
  Sparkles,
  CheckCircle2,
  XCircle,
  Loader2,
  Eye,
  EyeOff,
  RefreshCw,
  ListChecks,
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
  ai_provider: 'openai',
  gemini_api_key: '',
  gemini_model: 'gemini-2.5-flash',
  openai_api_key: '',
  openai_model: 'gpt-5.4',
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
  const [referenceReviewError, setReferenceReviewError] = useState<string | null>(null);
  const [referenceReviewMessage, setReferenceReviewMessage] = useState<string | null>(null);
  const [referenceRefreshError, setReferenceRefreshError] = useState<string | null>(null);
  const [referenceRefreshMessage, setReferenceRefreshMessage] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  // Load current settings
  const { data: current, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: fetchSettings,
  });

  const { data: referenceCache, isLoading: referenceCacheLoading } = useQuery({
    queryKey: ['reference-cache-status'],
    queryFn: fetchReferenceCacheStatus,
  });

  const { data: referenceCandidates } = useQuery({
    queryKey: ['reference-cache-candidates'],
    queryFn: fetchReferenceCacheCandidates,
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
        ai_provider: current.ai_provider === 'gemini' ? 'gemini' : 'openai',
        gemini_api_key: '', // don't populate masked keys
        gemini_model: current.gemini_model || 'gemini-2.5-flash',
        openai_api_key: '', // don't populate masked keys
        openai_model: current.openai_model || 'gpt-5.4',
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

  const reviewReferenceCandidateMut = useMutation({
    mutationFn: reviewReferenceCacheCandidate,
    onMutate: () => {
      setReferenceReviewError(null);
      setReferenceReviewMessage(null);
    },
    onSuccess: (result) => {
      setReferenceReviewMessage(
        result.enabled
          ? `Review saved: ${result.sourceTable}. Refresh from the enabled table list.`
          : `Review saved: ${result.sourceTable}`,
      );
      queryClient.invalidateQueries({ queryKey: ['reference-cache-status'] });
      queryClient.invalidateQueries({ queryKey: ['reference-cache-candidates'] });
    },
    onError: (error) => {
      setReferenceReviewError(extractApiError(error, 'Review failed'));
    },
  });

  const refreshReferenceTableMut = useMutation({
    mutationFn: refreshReferenceCacheTable,
    onMutate: () => {
      setReferenceRefreshError(null);
      setReferenceRefreshMessage(null);
    },
    onSuccess: (result) => {
      setReferenceRefreshMessage(`Refresh saved: ${result.sourceTable} (${result.rowCount.toLocaleString()} rows)`);
      queryClient.invalidateQueries({ queryKey: ['reference-cache-status'] });
    },
    onError: (error) => {
      setReferenceRefreshError(extractApiError(error, 'Refresh failed'));
    },
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
    <div className="max-w-2xl mx-auto p-4 sm:p-6">
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

      {/* ── Local reference cache ─────────────── */}
      <section className="mb-8">
        <div className="flex items-center gap-2 mb-4">
          <ListChecks size={18} className="text-slate-700" />
          <h3 className="text-lg font-semibold">Local Reference Cache</h3>
          {referenceCache?.available && referenceCache.failedTables === 0 && (
            <span className="ml-auto flex items-center gap-1 text-xs text-green-600">
              <CheckCircle2 size={14} /> Ready
            </span>
          )}
          {referenceCache?.available && referenceCache.failedTables > 0 && (
            <span className="ml-auto flex items-center gap-1 text-xs text-amber-700">
              <XCircle size={14} /> Needs review
            </span>
          )}
        </div>

        <div className="bg-white border rounded-lg p-5">
          {referenceCacheLoading && (
            <div className="flex items-center gap-2 text-sm text-gray-500">
              <Loader2 size={14} className="animate-spin" />
              Loading reference cache status…
            </div>
          )}

          {!referenceCacheLoading && !referenceCache?.available && (
            <div className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              Reference cache tables are not available yet. Run the reference-cache migration and refresh command.
              {referenceCache?.error && (
                <div className="mt-1 text-xs text-amber-700">{referenceCache.error}</div>
              )}
            </div>
          )}

          {!referenceCacheLoading && referenceCache?.available && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <Metric label="Enabled tables" value={referenceCache.enabledTables} />
                <Metric label="Local rows" value={referenceCache.activeRows} />
                <Metric label="Review queue" value={referenceCache.manualReviewTables} />
                <Metric label="Failed" value={referenceCache.failedTables} tone={referenceCache.failedTables > 0 ? 'warn' : 'normal'} />
              </div>

              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                <span>Last refresh: {referenceCache.lastRefreshedAt || 'never'}</span>
                <span>Disabled cacheable candidates: {referenceCache.disabledCacheableTables}</span>
              </div>

              <div className="border rounded-lg overflow-hidden">
                {(referenceRefreshError || referenceRefreshMessage) && (
                  <div className={`border-b px-3 py-2 text-xs ${
                    referenceRefreshError
                      ? 'border-red-100 bg-red-50 text-red-700'
                      : 'border-green-100 bg-green-50 text-green-700'
                  }`}>
                    {referenceRefreshError
                      ? `Refresh failed: ${referenceRefreshError}`
                      : referenceRefreshMessage}
                  </div>
                )}
                <div className="max-h-56 overflow-auto">
                  <table className="w-full text-xs">
                    <thead className="sticky top-0 bg-gray-50 text-gray-500">
                      <tr>
                        <th className="text-left font-medium px-3 py-2">Table</th>
                        <th className="text-right font-medium px-3 py-2">Rows</th>
                        <th className="text-left font-medium px-3 py-2">Status</th>
                        <th className="text-right font-medium px-3 py-2">Refresh</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {referenceCache.tables.map((table) => (
                        <tr key={table.sourceTable}>
                          <td className="px-3 py-2 text-gray-800">{table.sourceTable}</td>
                          <td className="px-3 py-2 text-right tabular-nums text-gray-700">
                            {table.rowCount ?? 'n/a'}
                          </td>
                          <td className="px-3 py-2">
                            <span className={`inline-flex rounded px-2 py-0.5 ${
                              table.lastRefreshStatus === 'success'
                                ? 'bg-green-50 text-green-700'
                                : table.lastRefreshStatus === 'failed'
                                  ? 'bg-red-50 text-red-700'
                                  : 'bg-gray-100 text-gray-600'
                            }`}>
                              {table.lastRefreshStatus}
                            </span>
                          </td>
                          <td className="px-3 py-2 text-right">
                            <button
                              type="button"
                              onClick={() => refreshReferenceTableMut.mutate({ sourceTable: table.sourceTable })}
                              disabled={refreshReferenceTableMut.isPending}
                              className="inline-flex items-center justify-center rounded border border-gray-200 bg-white p-1 text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                              title="Refresh table"
                            >
                              {refreshReferenceTableMut.isPending ? (
                                <Loader2 size={13} className="animate-spin" />
                              ) : (
                                <RefreshCw size={13} />
                              )}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              {referenceCandidates?.available && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                  <div className="border rounded-lg overflow-hidden">
                    <div className="bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600">
                      Candidate Review By Schema
                    </div>
                    <div className="max-h-44 overflow-auto">
                      <table className="w-full text-xs">
                        <tbody className="divide-y">
                          {referenceCandidates.summaryBySchema.slice(0, 12).map((row) => (
                            <tr key={`${row.classification}-${row.sourceSchema}`}>
                              <td className="px-3 py-2 text-gray-700">{row.sourceSchema}</td>
                              <td className="px-3 py-2 text-gray-500">{row.classification}</td>
                              <td className="px-3 py-2 text-right tabular-nums text-gray-800">{row.tableCount}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>

                  <div className="border rounded-lg overflow-hidden">
                    <div className="bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600">
                      Small Disabled Candidates
                    </div>
                    {(referenceReviewError || referenceReviewMessage) && (
                      <div className={`border-t px-3 py-2 text-xs ${
                        referenceReviewError
                          ? 'border-red-100 bg-red-50 text-red-700'
                          : 'border-green-100 bg-green-50 text-green-700'
                      }`}>
                        {referenceReviewError
                          ? `Review failed: ${referenceReviewError}`
                          : referenceReviewMessage}
                      </div>
                    )}
                    <div className="max-h-44 overflow-auto">
                      <table className="w-full text-xs">
                        <tbody className="divide-y">
                          {referenceCandidates.candidates.slice(0, 12).map((candidate) => (
                            <tr key={candidate.sourceTable}>
                              <td className="px-3 py-2 text-gray-800">{candidate.sourceTable}</td>
                              <td className="px-3 py-2 text-right tabular-nums text-gray-600">
                                {candidate.estimatedRows ?? 'n/a'}
                              </td>
                              <td className="px-3 py-2">
                                <div className="flex justify-end gap-1">
                                  <button
                                    type="button"
                                    onClick={() => reviewReferenceCandidateMut.mutate({
                                      sourceTable: candidate.sourceTable,
                                      decision: 'enable',
                                    })}
                                    disabled={reviewReferenceCandidateMut.isPending}
                                    className="inline-flex items-center justify-center rounded border border-green-200 bg-green-50 p-1 text-green-700 hover:bg-green-100 disabled:opacity-50"
                                    title="Enable table for local reference cache"
                                  >
                                    <CheckCircle2 size={13} />
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => reviewReferenceCandidateMut.mutate({
                                      sourceTable: candidate.sourceTable,
                                      decision: 'reject',
                                    })}
                                    disabled={reviewReferenceCandidateMut.isPending}
                                    className="inline-flex items-center justify-center rounded border border-gray-200 bg-white p-1 text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                                    title="Mark table as do not cache"
                                  >
                                    <XCircle size={13} />
                                  </button>
                                </div>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}
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
              placeholder="gpt-5.4"
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

function Metric({
  label,
  value,
  tone = 'normal',
}: {
  label: string;
  value: number;
  tone?: 'normal' | 'warn';
}) {
  return (
    <div className={`rounded-lg border px-3 py-2 ${
      tone === 'warn' ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-200'
    }`}>
      <div className={`text-lg font-semibold tabular-nums ${
        tone === 'warn' ? 'text-amber-800' : 'text-gray-900'
      }`}>
        {value.toLocaleString()}
      </div>
      <div className="text-xs text-gray-500">{label}</div>
    </div>
  );
}

function extractApiError(error: unknown, fallback: string): string {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const response = (error as { response?: { data?: { error?: unknown } } }).response;
    if (typeof response?.data?.error === 'string' && response.data.error.trim() !== '') {
      return response.data.error;
    }
  }

  if (error instanceof Error && error.message.trim() !== '') {
    return error.message;
  }

  return fallback;
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
