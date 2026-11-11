import { AlertCircle } from 'lucide-react';

export interface RefreshError {
  message: string;
  fieldErrors: Record<string, string>;
}

export function formatRefreshError(error: unknown, fallback: string): RefreshError {
  const payload = (error as { response?: { data?: unknown } } | null)?.response?.data;
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return { message: fallback, fieldErrors: {} };
  }

  const data = payload as Record<string, unknown>;
  const fields = data.fieldErrors;
  const fieldErrors = fields && typeof fields === 'object' && !Array.isArray(fields)
    ? Object.fromEntries(Object.entries(fields).filter((entry): entry is [string, string] => typeof entry[1] === 'string'))
    : {};
  return { message: typeof data.error === 'string' && data.error ? data.error : fallback, fieldErrors };
}

export default function RefreshErrorNotice({ error }: { error: RefreshError | null }) {
  if (!error) return null;
  return (
    <div role="alert" className="flex flex-col gap-1 text-sm text-red-600 px-4 py-2">
      <span className="flex items-center gap-2"><AlertCircle size={14} />{error.message}</span>
      {Object.entries(error.fieldErrors).map(([field, message]) => <span key={field}>{field}: {message}</span>)}
    </div>
  );
}
