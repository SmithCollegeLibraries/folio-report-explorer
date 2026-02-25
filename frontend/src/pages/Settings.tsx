import { useState, useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchSettings, saveSettings, testSettings } from '../api/client';
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
} from 'lucide-react';

interface SettingsData {
  pg_host: string;
  pg_port: string;
  pg_db: string;
  pg_user: string;
  pg_pass: string;
  pg_sslmode: string;
  gemini_api_key: string;
  gemini_model: string;
}

const EMPTY: SettingsData = {
  pg_host: '',
  pg_port: '5432',
  pg_db: 'ldplite',
  pg_user: '',
  pg_pass: '',
  pg_sslmode: 'require',
  gemini_api_key: '',
  gemini_model: 'gemini-2.5-flash',
};

const SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

export default function Settings() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<SettingsData>(EMPTY);
  const [showPgPass, setShowPgPass] = useState(false);
  const [showGeminiKey, setShowGeminiKey] = useState(false);
  const [pgTestResult, setPgTestResult] = useState<{
    connected: boolean;
    version?: string;
    error?: string;
  } | null>(null);
  const [geminiTestResult, setGeminiTestResult] = useState<{
    connected: boolean;
    model?: string;
    displayName?: string;
    error?: string;
  } | null>(null);
  const [saved, setSaved] = useState(false);

  // Load current settings
  const { data: current, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: fetchSettings,
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
        gemini_api_key: '', // don't populate masked keys
        gemini_model: current.gemini_model || 'gemini-2.5-flash',
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
    onSuccess: (data) => setPgTestResult(data.postgres),
  });

  const testGeminiMut = useMutation({
    mutationFn: () =>
      testSettings({
        test_gemini: true,
        gemini_api_key: form.gemini_api_key || undefined,
        gemini_model: form.gemini_model,
      }),
    onSuccess: (data) => setGeminiTestResult(data.gemini),
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
    if (form.gemini_api_key) payload.gemini_api_key = form.gemini_api_key;
    if (form.gemini_model) payload.gemini_model = form.gemini_model;
    saveMut.mutate(payload);
  };

  const update = (field: keyof SettingsData, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    setPgTestResult(null);
    setGeminiTestResult(null);
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

  return (
    <div className="max-w-2xl mx-auto p-6">
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
          <div className="grid grid-cols-3 gap-4">
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

          <div className="grid grid-cols-2 gap-4">
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
