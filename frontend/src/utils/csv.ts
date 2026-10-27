/**
 * Shared CSV export utility.
 * Used by ResultsTable (ExecuteResponse) and HistoryResultsModal (JobStatusResponse).
 */

function escapeCell(v: unknown): string {
  const s = v == null ? '' : String(v);
  return s.includes(',') || s.includes('"') || s.includes('\n')
    ? `"${s.replace(/"/g, '""')}"` : s;
}

/** Parse RFC 4180 style CSV rows into records keyed by the header row. */
export function parseCsvRecords(text: string): Array<Record<string, string>> {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = '';
  let quoted = false;
  const source = text.replace(/^\uFEFF/, '');
  for (let index = 0; index < source.length; index += 1) {
    const char = source[index];
    if (quoted && char === '"' && source[index + 1] === '"') {
      cell += '"';
      index += 1;
    } else if (char === '"') {
      quoted = !quoted;
    } else if (!quoted && char === ',') {
      row.push(cell);
      cell = '';
    } else if (!quoted && (char === '\n' || char === '\r')) {
      if (char === '\r' && source[index + 1] === '\n') index += 1;
      row.push(cell);
      if (row.some((value) => value.trim() !== '')) rows.push(row);
      row = [];
      cell = '';
    } else {
      cell += char;
    }
  }
  row.push(cell);
  if (row.some((value) => value.trim() !== '')) rows.push(row);
  if (quoted || rows.length < 2) throw new Error('Choose a CSV file with a header and at least one data row.');

  const headers = rows[0].map((header) => header.trim().toLowerCase());
  if (new Set(headers).size !== headers.length || headers.some((header) => !header)) {
    throw new Error('CSV headers must be non-empty and unique.');
  }
  return rows.slice(1).map((values) => Object.fromEntries(headers.map((header, index) => [header, values[index] ?? ''])));
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
