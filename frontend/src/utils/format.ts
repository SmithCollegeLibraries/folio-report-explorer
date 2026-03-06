/**
 * General-purpose display formatters.
 */

/**
 * Format a duration in milliseconds to a human-readable string.
 * e.g. 125 → "125ms", 2500 → "2.5s", 90000 → "1m 30s"
 */
export function fmtTime(ms: number): string {
  if (ms >= 60000) return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
  if (ms >= 1000) return `${(ms / 1000).toFixed(1)}s`;
  return `${ms}ms`;
}

/**
 * Format an ISO date string to a localised "Mon DD, YYYY HH:MM" string.
 */
export function fmtDate(iso: string): string {
  const raw = (iso || '').trim();
  const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw);
  const normalized = raw.includes(' ') ? raw.replace(' ', 'T') : raw;
  const parseValue = hasTimezone ? normalized : `${normalized}Z`;
  const d = new Date(parseValue);

  if (Number.isNaN(d.getTime())) return iso;

  return (
    d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
    ' ' +
    d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  );
}
