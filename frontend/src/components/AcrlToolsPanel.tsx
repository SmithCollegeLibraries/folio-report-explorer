import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { confirmAcrlImport, getAcrlThreshold, previewAcrlImport, saveAcrlThreshold } from '../api/acrl';
import type { AcrlSurveyYear } from '../types/acrl';
import { parseCsvRecords } from '../utils/csv';

export default function AcrlToolsPanel({ years }: { years: AcrlSurveyYear[] }) {
  const queryClient = useQueryClient();
  const publishedYears = years.filter((year) => year.status === 'published').map((year) => year.surveyYear);
  const [year, setYear] = useState<number | ''>('');
  const [sourceLabel, setSourceLabel] = useState('');
  const [csvRows, setCsvRows] = useState<Array<Record<string, string>>>([]);
  const [overwrite, setOverwrite] = useState(false);
  const [threshold, setThreshold] = useState('10');
  const [fileError, setFileError] = useState('');
  const [importResult, setImportResult] = useState('');
  const thresholdQuery = useQuery({ queryKey: ['acrl', 'threshold'], queryFn: getAcrlThreshold });
  useEffect(() => {
    if (thresholdQuery.data) setThreshold(String(thresholdQuery.data.thresholdPercent));
  }, [thresholdQuery.data]);
  useEffect(() => {
    if (year === '' && publishedYears.length) setYear(publishedYears[0]);
  }, [publishedYears.join(','), year]);

  const thresholdMutation = useMutation({
    mutationFn: () => saveAcrlThreshold(Number(threshold)),
    onSuccess: (result) => {
      setThreshold(String(result.thresholdPercent));
      queryClient.setQueryData(['acrl', 'threshold'], result);
    },
  });
  const previewMutation = useMutation({
    mutationFn: () => previewAcrlImport(Number(year), { rows: csvRows, sourceLabel, overwrite }),
    onSuccess: () => setImportResult(''),
  });
  const confirmMutation = useMutation({
    mutationFn: () => confirmAcrlImport(Number(year), { rows: csvRows, sourceLabel, overwrite }),
    onSuccess: (result) => {
      setImportResult(`Imported ${result.result.imported}; unchanged ${result.result.unchanged}; skipped ${result.result.skipped}.`);
      previewMutation.reset();
    },
  });
  const preview = previewMutation.data;

  const handleFile = async (file?: File) => {
    setFileError('');
    setImportResult('');
    previewMutation.reset();
    if (!file) {
      setCsvRows([]);
      return;
    }
    try {
      const rows = parseCsvRecords(await file.text());
      if (!['question_id', 'part', 'value'].every((field) => field in rows[0])) {
        throw new Error('CSV needs question_id, part, and value columns.');
      }
      setCsvRows(rows);
    } catch (error) {
      setCsvRows([]);
      setFileError(error instanceof Error ? error.message : 'Could not read this CSV file.');
    }
  };

  return (
    <section aria-labelledby="acrl-tools-heading" className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-folio-600">Administrator tools</p>
        <h2 id="acrl-tools-heading" className="mt-1 text-lg font-semibold text-gray-900">ACRL values and review settings</h2>
      </div>
      <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); thresholdMutation.mutate(); }}>
        <label className="text-sm font-medium text-gray-700">Change highlight threshold (%)
          <input className="mt-1 block w-32 rounded border border-gray-300 px-3 py-2" type="number" min="0" max="100" step="0.01" value={threshold} onChange={(event) => setThreshold(event.target.value)} />
        </label>
        <button className="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" type="submit" disabled={thresholdMutation.isPending}>Save threshold</button>
        {thresholdMutation.error && <p role="alert" className="text-sm text-rose-700">Could not save the threshold.</p>}
      </form>

      <div className="space-y-3 border-t border-gray-100 pt-4">
        <h3 className="font-semibold text-gray-900">Import previous-year values</h3>
        <p className="text-sm text-gray-600">Preview matched parts, unknown IDs, type errors, and existing values before confirming. CSV columns: sheet (optional), question_id, part, value, note (optional).</p>
        <div className="grid gap-3 md:grid-cols-3 md:items-end">
          <label className="text-sm font-medium text-gray-700">Published survey year
            <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={year} onChange={(event) => setYear(event.target.value ? Number(event.target.value) : '')}>
              {publishedYears.map((publishedYear) => <option key={publishedYear} value={publishedYear}>{publishedYear}</option>)}
            </select>
          </label>
          <label className="text-sm font-medium text-gray-700">Source label
            <input className="mt-1 block w-full rounded border border-gray-300 px-3 py-2" value={sourceLabel} onChange={(event) => setSourceLabel(event.target.value)} maxLength={255} />
          </label>
          <label className="text-sm font-medium text-gray-700">CSV file
            <input className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" type="file" accept=".csv,text/csv" onChange={(event) => void handleFile(event.target.files?.[0])} />
          </label>
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={overwrite} onChange={(event) => { setOverwrite(event.target.checked); previewMutation.reset(); }} />Overwrite existing non-empty values</label>
        {fileError && <p role="alert" className="text-sm text-rose-700">{fileError}</p>}
        {csvRows.length > 0 && <p className="text-sm text-gray-600">{csvRows.length} CSV answer rows loaded.</p>}
        <div className="flex flex-wrap gap-2">
          <button className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white hover:bg-folio-800 disabled:opacity-50" disabled={!year || !sourceLabel.trim() || !csvRows.length || previewMutation.isPending} onClick={() => previewMutation.mutate()}>Preview import</button>
          <button className="rounded border border-folio-700 px-4 py-2 text-sm font-semibold text-folio-800 disabled:opacity-50" disabled={!preview || preview.unknownCount > 0 || preview.typeErrorCount > 0 || confirmMutation.isPending} onClick={() => confirmMutation.mutate()}>Confirm import</button>
        </div>
        {previewMutation.error && <p role="alert" className="text-sm text-rose-700">Could not preview this import.</p>}
        {confirmMutation.error && <p role="alert" className="text-sm text-rose-700">Could not confirm this import.</p>}
        {importResult && <p role="status" className="rounded bg-emerald-50 p-3 text-sm text-emerald-800">{importResult}</p>}
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
