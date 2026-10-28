import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { confirmAcrlNotesImport, getAcrlReview, previewAcrlNotesImport } from '../api/acrl';
import AcrlFileStatus from './AcrlFileStatus';
import { parseCsvRecords } from '../utils/csv';
import type { AcrlSurveyYear } from '../types/acrl';

export default function AcrlNotesImportPanel({ years }: { years: AcrlSurveyYear[] }) {
  const queryClient = useQueryClient();
  const [year, setYear] = useState<number | ''>('');
  const [rows, setRows] = useState<Array<Record<string, string>>>([]);
  const [error, setError] = useState('');
  const [result, setResult] = useState('');
  const [readingFile, setReadingFile] = useState(false);
  useEffect(() => {
    if (year === '' && years.length) setYear(years.find((item) => item.surveyYear === 2026)?.surveyYear ?? years[0].surveyYear);
  }, [year, years]);
  const review = useQuery({ queryKey: ['acrl', 'review', year], queryFn: () => getAcrlReview(Number(year)), enabled: year !== '' });
  const preview = useMutation({
    mutationFn: () => previewAcrlNotesImport(Number(year), rows),
    onSuccess: () => setResult(`Preview ready: ${rows.length} ${rows.length === 1 ? 'row' : 'rows'} checked.`),
  });
  const confirm = useMutation({
    mutationFn: () => confirmAcrlNotesImport(Number(year), rows),
    onSuccess: ({ result: imported }) => {
      setResult(`Imported ${imported.imported}; unchanged ${imported.unchanged}; skipped ${imported.skipped}.`);
      preview.reset();
      queryClient.invalidateQueries({ queryKey: ['acrl-question-details'] });
      queryClient.invalidateQueries({ queryKey: ['acrl-entry'] });
    },
  });
  const questionKeys = new Set((review.data?.items || []).filter((item) => item.current?.sheet === 'survey')
    .map((item) => `${item.current?.question_id}:${item.current?.part ?? ''}`));
  const handleFile = async (file?: File) => {
    setRows([]);
    setError('');
    setResult('');
    preview.reset();
    confirm.reset();
    if (!file) {
      setReadingFile(false);
      return;
    }
    setReadingFile(true);
    try {
      const parsed = parseCsvRecords(await file.text());
      if (!['question_id', 'part', 'note', 'source'].every((field) => field in parsed[0])) {
        throw new Error('CSV needs question_id, part, note, and source columns.');
      }
      setRows(parsed);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Could not read this CSV file.');
    } finally {
      setReadingFile(false);
    }
  };
  const previewData = preview.data;
  const canConfirm = previewData && !previewData.unknownCount && !previewData.invalidCount && previewData.matchedCount === rows.length;
  const busy = readingFile || preview.isPending || confirm.isPending;
  const statusMessage = readingFile
    ? 'Reading file…'
    : preview.isPending
      ? `Checking ${rows.length} ${rows.length === 1 ? 'row' : 'rows'}…`
      : confirm.isPending
        ? 'Importing…'
        : result;

  return <section aria-labelledby="acrl-notes-import-title" aria-busy={busy} className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
    <div>
      <p className="text-xs font-semibold uppercase tracking-[0.16em] text-folio-600">Administrator tools</p>
      <h2 id="acrl-notes-import-title" className="mt-1 text-lg font-semibold text-gray-900">Import collection notes</h2>
      <p className="mt-1 text-sm leading-6 text-gray-600">Preview question matches and source years before importing notes. Re-importing an unchanged CSV is safe.</p>
    </div>
    <div className="grid gap-3 sm:grid-cols-[10rem_minmax(16rem,1fr)] sm:items-end">
      <label className="text-sm font-medium text-gray-700">Survey year
        <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={year} disabled={busy} onChange={(event) => { setYear(Number(event.target.value)); preview.reset(); setResult(''); }}>
          {years.map((item) => <option key={item.surveyYear} value={item.surveyYear}>{item.surveyYear}</option>)}
        </select>
      </label>
      <label className="text-sm font-medium text-gray-700">Collection notes CSV
        <input className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" type="file" accept=".csv,text/csv" aria-label="Collection notes CSV" disabled={busy} onChange={(event) => { const file = event.currentTarget.files?.[0]; event.currentTarget.value = ''; void handleFile(file); }} />
      </label>
    </div>
    {review.isLoading && <p role="status" className="text-sm text-gray-600">Loading question catalogue…</p>}
    {review.error && <p role="alert" className="text-sm text-rose-700">Could not load the selected year’s question catalogue.</p>}
    {rows.length > 0 && <p className="text-sm text-gray-700">{rows.length} note rows loaded · {rows.filter((row) => questionKeys.has(`${row.question_id}:${row.part}`)).length} question keys found in this year</p>}
    {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
    <AcrlFileStatus message={statusMessage || null} busy={busy} className={busy ? 'font-medium' : 'rounded bg-emerald-50 p-3 text-emerald-800'} />
    <div className="flex flex-wrap gap-2">
      <button className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" type="button" disabled={busy || !rows.length || year === ''} onClick={() => preview.mutate()}>Preview notes import</button>
      <button className="rounded border border-folio-700 px-4 py-2 text-sm font-semibold text-folio-800 disabled:opacity-50" type="button" disabled={busy || !canConfirm} onClick={() => confirm.mutate()}>Confirm notes import</button>
    </div>
    {preview.error && <p role="alert" className="text-sm text-rose-700">Could not preview this notes import.</p>}
    {confirm.error && <p role="alert" className="text-sm text-rose-700">Could not import these notes.</p>}
    {previewData && <div aria-label="Collection notes import preview" className="space-y-2 rounded border border-gray-200 bg-gray-50 p-3">
      <p className="text-sm font-medium text-gray-800">Matched {previewData.matchedCount} · unknown {previewData.unknownCount} · invalid {previewData.invalidCount}</p>
      <ul className="max-h-48 space-y-1 overflow-auto text-sm text-gray-700">{previewData.items.map((item) => <li key={`${item.line}-${item.questionId || 'unknown'}`}>
        Line {item.line} · Q{item.questionId || 'unknown'}{item.part ? ` ${item.part}` : ''} · FY{item.originYear ?? '—'} · {item.status.replace(/_/g, ' ')}{item.error ? ` · ${item.error}` : ''}
      </li>)}</ul>
    </div>}
  </section>;
}
