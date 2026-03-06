/**
 * Shared CSV export utility.
 * Used by ResultsTable (ExecuteResponse) and HistoryResultsModal (JobStatusResponse).
 */

function escapeCell(v: unknown): string {
  const s = v == null ? '' : String(v);
  return s.includes(',') || s.includes('"') || s.includes('\n')
    ? `"${s.replace(/"/g, '""')}"` : s;
}

/**
 * Build a CSV string from column names and row data, then trigger a browser download.
 *
 * @param columns  Ordered list of column names.
 * @param rows     Array of row objects keyed by column name.
 * @param filename Desired filename (without extension). Non-ASCII safe chars are replaced.
 */
export function downloadCsv(
  columns: string[],
  rows: Record<string, unknown>[],
  filename: string,
): void {
  const header = columns.map(escapeCell).join(',');
  const body = rows.map((r) => columns.map((c) => escapeCell(r[c])).join(','));
  const csv = [header, ...body].join('\r\n');

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${filename.replace(/[^a-z0-9_-]/gi, '_')}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}
