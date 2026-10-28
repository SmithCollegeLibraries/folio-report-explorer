import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { confirmAcrlImport, getAcrlThreshold, previewAcrlImport, saveAcrlThreshold } from '../api/acrl';
import AcrlFileStatus from './AcrlFileStatus';
import type { AcrlSurveyYear } from '../types/acrl';
import { getPublishedAcrlYears } from '../utils/acrl';
import { parseCsvRecords } from '../utils/csv';

export default function AcrlToolsPanel({ years }: { years: AcrlSurveyYear[] }) {
  const queryClient = useQueryClient();
  const publishedYears = getPublishedAcrlYears(years);
  const [year, setYear] = useState<number | ''>('');
  const [sourceLabel, setSourceLabel] = useState('');
  const [csvRows, setCsvRows] = useState<Array<Record<string, string>>>([]);
  const [csvFileName, setCsvFileName] = useState('');
  const [overwrite, setOverwrite] = useState(false);
  const [threshold, setThreshold] = useState('10');
  const [fileError, setFileError] = useState('');
  const [importResult, setImportResult] = useState('');
  const [readingFile, setReadingFile] = useState(false);
  const thresholdQuery = useQuery({ queryKey: ['acrl', 'threshold'], queryFn: getAcrlThreshold });
  useEffect(() => {
    if (thresholdQuery.data) setThreshold(String(thresholdQuery.data.thresholdPercent));
  }, [thresholdQuery.data]);
  const thresholdMutation = useMutation({
    mutationFn: () => saveAcrlThreshold(Number(threshold)),
    onSuccess: (result) => {
      setThreshold(String(result.thresholdPercent));
      queryClient.setQueryData(['acrl', 'threshold'], result);
    },
  });
  const previewMutation = useMutation({
    mutationFn: () => previewAcrlImport(Number(year), { rows: csvRows, sourceLabel, overwrite }),
    onSuccess: () => setImportResult(`Preview ready: ${csvRows.length} ${csvRows.length === 1 ? 'row' : 'rows'} checked.`),
  });
  const confirmMutation = useMutation({
    mutationFn: () => confirmAcrlImport(Number(year), { rows: csvRows, sourceLabel, overwrite }),
    onSuccess: (result) => {
      setImportResult(`Imported ${result.result.imported}; unchanged ${result.result.unchanged}; skipped ${result.result.skipped}.`);
      setYear('');
      previewMutation.reset();
    },
  });
  const preview = previewMutation.data;
  const confirmIssues = preview
    ? [
        preview.unknownCount > 0
          ? `${preview.unknownCount} ${preview.unknownCount === 1 ? 'row has' : 'rows have'} unknown IDs`
          : null,
        preview.typeErrorCount > 0
          ? `${preview.typeErrorCount} ${preview.typeErrorCount === 1 ? 'row has' : 'rows have'} type errors`
          : null,
      ].filter((issue): issue is string => issue !== null)
    : [];
  const canConfirm = Boolean(preview) && confirmIssues.length === 0;
  const confirmHint = preview && !canConfirm
    ? `Confirm is unavailable: ${confirmIssues.join(' and ')}. Check the survey year, or fix those rows in the CSV.`
    : null;

  const handleFile = async (file?: File) => {
    setFileError('');
    setImportResult('');
    previewMutation.reset();
    confirmMutation.reset();
    if (!file) {
      setCsvFileName('');
      setCsvRows([]);
      setReadingFile(false);
      return;
    }
    setCsvFileName(file.name);
    setReadingFile(true);
    try {
      const rows = parseCsvRecords(await file.text());
      if (!['question_id', 'part', 'value'].every((field) => field in rows[0])) {
        throw new Error('CSV needs question_id, part, and value columns.');
      }
      setCsvRows(rows);
    } catch (error) {
      setCsvRows([]);
      setFileError(error instanceof Error ? error.message : 'Could not read this CSV file.');
    } finally {
      setReadingFile(false);
    }
  };
  const busy = readingFile || previewMutation.isPending || confirmMutation.isPending;
  const hasPublishedYear = year !== '' && publishedYears.includes(year);
  const hasSourceLabel = Boolean(sourceLabel.trim());
  const hasCsvRows = csvRows.length > 0;
  const canPreview = hasPublishedYear && hasSourceLabel && hasCsvRows;
  const fileYear = csvFileName.match(/(?:^|[^0-9])([0-9]{4})(?![0-9])/)?.[1];
  const fileYearWarning = fileYear && year !== '' && Number(fileYear) !== year
    ? `This file looks like FY${fileYear} data, but the selected survey year is ${year}.`
    : null;
  const previewHint = busy || canPreview
    ? null
    : publishedYears.length === 0
      ? "No published survey years yet. Publish a year's catalogue first."
      : !hasPublishedYear
        ? 'Choose a published survey year.'
        : !hasSourceLabel
          ? 'Add a source label to preview this import.'
          : 'Choose a CSV file.';
  const statusMessage = readingFile
    ? 'Reading file…'
    : previewMutation.isPending
      ? `Checking ${csvRows.length} ${csvRows.length === 1 ? 'row' : 'rows'}…`
      : confirmMutation.isPending
        ? 'Importing…'
        : importResult;

  return (
    <section aria-labelledby="acrl-tools-heading" aria-busy={busy} className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-folio-600">Administrator tools</p>
        <h2 id="acrl-tools-heading" className="mt-1 text-lg font-semibold text-gray-900">ACRL values and review settings</h2>
      </div>
      <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); thresholdMutation.mutate(); }}>
        <label className="text-sm font-medium text-gray-700">Change highlight threshold (%)
          <input className="mt-1 block w-32 rounded border border-gray-300 px-3 py-2" type="number" min="0" max="100" step="0.01" value={threshold} disabled={busy || thresholdMutation.isPending} onChange={(event) => setThreshold(event.target.value)} />
        </label>
        <button className="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" type="submit" disabled={busy || thresholdMutation.isPending}>Save threshold</button>
        {thresholdMutation.error && <p role="alert" className="text-sm text-rose-700">Could not save the threshold.</p>}
      </form>

      <div role="region" aria-label="Import previous-year values" aria-busy={busy} className="space-y-3 border-t border-gray-100 pt-4">
        <h3 className="font-semibold text-gray-900">Import previous-year values</h3>
        <p className="text-sm text-gray-600">Preview matched parts, unknown IDs, type errors, and existing values before confirming. CSV columns: sheet (optional), question_id, part, value, note (optional).</p>
        <div className="grid gap-3 md:grid-cols-3 md:items-end">
          <label className="text-sm font-medium text-gray-700">Published survey year
            <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={year} disabled={busy} onChange={(event) => { setYear(event.target.value ? Number(event.target.value) : ''); previewMutation.reset(); confirmMutation.reset(); setImportResult(''); }}>
              <option value="">Choose a year…</option>
              {publishedYears.map((publishedYear) => <option key={publishedYear} value={publishedYear}>{publishedYear}</option>)}
            </select>
          </label>
          <label className="text-sm font-medium text-gray-700">Source label <span>(required)</span>
            <input className="mt-1 block w-full rounded border border-gray-300 px-3 py-2" value={sourceLabel} disabled={busy} onChange={(event) => setSourceLabel(event.target.value)} maxLength={255} required aria-required="true" placeholder="e.g. FY2025 Benchmark submission" />
          </label>
          <label className="text-sm font-medium text-gray-700">CSV file
            <input className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" type="file" accept=".csv,text/csv" disabled={busy} onChange={(event) => { const file = event.currentTarget.files?.[0]; event.currentTarget.value = ''; void handleFile(file); }} />
          </label>
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={overwrite} disabled={busy} onChange={(event) => { setOverwrite(event.target.checked); previewMutation.reset(); setImportResult(''); }} />Overwrite existing non-empty values</label>
        {fileError && <p role="alert" className="text-sm text-rose-700">{fileError}</p>}
        {csvRows.length > 0 && <p className="text-sm text-gray-600">{csvRows.length} CSV answer rows loaded.</p>}
        <AcrlFileStatus message={statusMessage || null} busy={busy} className={busy ? 'font-medium' : 'rounded bg-emerald-50 p-3 text-emerald-800'} />
        {fileYearWarning && <p role="status" className="text-sm text-amber-800">{fileYearWarning}</p>}
        <div className="flex flex-wrap gap-2">
          <button className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white hover:bg-folio-800 disabled:opacity-50" disabled={busy || !canPreview} aria-describedby={previewHint ? 'acrl-import-preview-hint' : undefined} onClick={() => previewMutation.mutate()}>Preview import</button>
          <button className="rounded border border-folio-700 px-4 py-2 text-sm font-semibold text-folio-800 disabled:opacity-50" disabled={busy || !canConfirm} aria-describedby={confirmHint ? 'acrl-confirm-import-hint' : undefined} onClick={() => confirmMutation.mutate()}>Confirm import</button>
        </div>
        {previewHint && <p id="acrl-import-preview-hint" className="text-sm text-gray-600">{previewHint}</p>}
        {confirmHint && <p id="acrl-confirm-import-hint" className="text-sm text-gray-600">{confirmHint}</p>}
        {previewMutation.error && <p role="alert" className="text-sm text-rose-700">Could not preview this import.</p>}
        {confirmMutation.error && <p role="alert" className="text-sm text-rose-700">Could not confirm this import.</p>}
        {preview && <div aria-label="Import preview" className="space-y-2 rounded border border-gray-200 bg-gray-50 p-3">
          <p className="text-sm font-medium text-gray-800">Matched {preview.matchedCount} · unknown IDs {preview.unknownCount} · type errors {preview.typeErrorCount} · existing values that would change {preview.existingChanges}</p>
          <ul className="max-h-48 space-y-1 overflow-auto text-sm text-gray-700">
            {preview.items.map((item) => <li key={`${item.line}-${item.questionId || 'unknown'}`} className="flex flex-wrap gap-x-2"><span>Line {item.line}</span><span>{item.questionId || 'Unknown'}{item.part ? ` ${item.part}` : ''}</span><span>{item.status.replace(/_/g, ' ')}</span>{item.currentValue !== null && <span>current {item.currentValue} → proposed {item.value}</span>}{item.error && <span className="text-rose-700">{item.error}</span>}</li>)}
          </ul>
        </div>}
      </div>
    </section>
  );
}
