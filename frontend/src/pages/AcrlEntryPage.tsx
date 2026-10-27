import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { getAcrlEntry, saveAcrlAnswer, setAcrlSectionStatus } from '../api/acrl';
import { useAuth } from '../hooks/useAuth';
import type { AcrlEntryQuestion, AcrlEntryResponse, AcrlEntrySection } from '../types/acrl';

function errorText(error: unknown): string {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const response = (error as { response?: { status?: number; data?: { current?: { value_text?: string | null } } } }).response;
    if (response?.status === 409) return `This answer changed while you were editing. Current value: ${response.data?.current?.value_text ?? 'blank'}. Reload the section before saving again.`;
  }
  return 'The request could not be completed. Please try again.';
}

function AcrlAnswerRow({ year, question, locked, isAdmin, onSaved }: {
  year: number;
  question: AcrlEntryQuestion;
  locked: boolean;
  isAdmin: boolean;
  onSaved: () => void;
}) {
  const [value, setValue] = useState(question.answer.value_text ?? '');
  const [note, setNote] = useState(question.answer.note ?? '');
  const [conflict, setConflict] = useState('');
  useEffect(() => {
    setValue(question.answer.value_text ?? '');
    setNote(question.answer.note ?? '');
  }, [question.answer.updated_at]);
  const saveMutation = useMutation({
    mutationFn: () => saveAcrlAnswer(year, {
      sheet: question.sheet,
      question_id: question.questionId,
      part: question.part,
      value,
      note,
      expected_updated_at: question.answer.updated_at,
    }),
    onSuccess: () => { setConflict(''); onSaved(); },
    onError: (error) => setConflict(errorText(error)),
  });
  const disabled = locked && !isAdmin;
  const numberInput = question.answerType === 'number' || question.answerType === 'currency';
  const currentCalculated = question.answer.value_number ?? question.answer.value_text;
  const isCalculated = Boolean(question.calculatedFrom);
  const calculationLabel = question.calculatedFrom?.replace(/^sum of\s+/i, '').replace(/,\s*/g, ' + ');
  return (
    <article className={`space-y-3 rounded-lg border p-4 ${question.comparison.highlighted ? 'border-amber-400 bg-amber-50' : 'border-gray-200 bg-white'}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Q{question.questionId}{question.part ? ` · ${question.part}` : ''}{question.required === 'yes' ? ' · Required' : ''}</p>
          <h3 className="mt-1 font-medium text-gray-900">{question.prompt}</h3>
        </div>
        {question.comparison.changePercent !== null && <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${question.comparison.highlighted ? 'bg-amber-200 text-amber-950' : 'bg-gray-100 text-gray-700'}`} aria-label={`Change ${question.comparison.changePercent}%`}>
          {question.comparison.changePercent > 0 ? '+' : ''}{question.comparison.changePercent.toFixed(2)}%
        </span>}
      </div>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600">
        <span>Previous value: {question.previous.label}</span>
        {question.previous.isSum && <span className="text-xs text-gray-500">Approved linked values summed</span>}
        {question.comparison.highlighted && <span className="font-medium text-amber-900">Change meets the highlight threshold. Add a note explaining the change.</span>}
      </div>
      {isCalculated && question.calculated?.supported && <div className="space-y-1 text-sm text-gray-700">
        <p>Calculated from {calculationLabel}</p>
        <p className={question.calculated.mismatch ? 'font-medium text-amber-900' : 'text-gray-600'}>
          Calculated value: {question.calculated.value === null ? `Waiting for ${question.calculated.waitingFor.join(', ') || 'inputs'}` : question.calculated.value}{question.calculated.mismatch ? ' · Entered value differs (advisory)' : ''}
        </p>
      </div>}
      {isCalculated && question.calculated && !question.calculated.supported && <p className="text-sm text-gray-600">Calculation: {question.calculated.raw}</p>}
      {!isCalculated && <div className="grid gap-3 md:grid-cols-[minmax(12rem,1fr)_minmax(12rem,1fr)_auto] md:items-end">
        <label className="text-sm font-medium text-gray-700">Answer
          {numberInput ? <input className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 disabled:bg-gray-100" type="number" step="0.01" value={value} disabled={disabled} onChange={(event) => setValue(event.target.value)} />
            : question.answerType === 'yes/no' ? <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2 disabled:bg-gray-100" value={value} disabled={disabled} onChange={(event) => setValue(event.target.value)}><option value="">Select…</option><option value="yes">Yes</option><option value="no">No</option></select>
              : <textarea className="mt-1 block min-h-10 w-full rounded border border-gray-300 px-3 py-2 disabled:bg-gray-100" value={value} disabled={disabled} onChange={(event) => setValue(event.target.value)} />}
        </label>
        <label className="text-sm font-medium text-gray-700">Note
          <textarea className="mt-1 block min-h-10 w-full rounded border border-gray-300 px-3 py-2 disabled:bg-gray-100" value={note} disabled={disabled} onChange={(event) => setNote(event.target.value)} />
        </label>
        <button className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white hover:bg-folio-800 disabled:opacity-50" disabled={disabled || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
          {saveMutation.isPending ? 'Saving…' : 'Save answer'}
        </button>
      </div>}
      {conflict && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">{conflict}</p>}
      {saveMutation.error && !conflict && <p role="alert" className="text-sm text-rose-700">Could not save this answer.</p>}
      {question.answer.source === 'import' && <p className="text-xs text-gray-500">Imported from {question.answer.source_label || 'an administrator source'}.</p>}
      <span className="sr-only">Current value: {currentCalculated ?? 'blank'}</span>
    </article>
  );
}

function SectionCard({ year, section, isAdmin, onChanged }: {
  year: number;
  section: AcrlEntrySection;
  isAdmin: boolean;
  onChanged: () => void;
}) {
  const locked = section.status === 'ready';
  const mutation = useMutation({
    mutationFn: () => setAcrlSectionStatus(year, section.name, locked ? 'open' : 'ready'),
    onSuccess: onChanged,
  });
  return (
    <section aria-label={`${section.name} section`} className="space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">{section.name}</h2>
          <p className="text-sm text-gray-600">{section.filled} of {section.total} parts filled</p>
        </div>
        <div className="flex items-center gap-2">
          <span className={`rounded-full px-3 py-1 text-xs font-semibold ${locked ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-700'}`}>{locked ? 'Ready for review' : 'Open'}</span>
          {locked ? isAdmin && <button className="rounded border border-gray-400 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-white disabled:opacity-50" disabled={mutation.isPending} onClick={() => mutation.mutate()}>Reopen section</button>
            : <button className="rounded border border-folio-700 px-3 py-1.5 text-sm font-medium text-folio-800 hover:bg-white disabled:opacity-50" disabled={mutation.isPending} onClick={() => mutation.mutate()}>Mark section ready</button>}
        </div>
      </div>
      {locked && !isAdmin && <p className="rounded bg-emerald-50 p-3 text-sm text-emerald-900">This section is ready for review and is locked for editing.</p>}
      {mutation.error && <p role="alert" className="text-sm text-rose-700">Could not update section status.</p>}
      <div className="space-y-3">
        {section.questions.map((question) => <AcrlAnswerRow key={`${question.questionId}-${question.part}`} year={year} question={question} locked={locked} isAdmin={isAdmin} onSaved={onChanged} />)}
      </div>
    </section>
  );
}

export default function AcrlEntryPage() {
  const { year: yearParam } = useParams<{ year: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { isAdmin } = useAuth();
  const entryQuery = useQuery({
    queryKey: ['acrl-entry', yearParam || 'latest'],
    queryFn: () => getAcrlEntry(yearParam ? Number(yearParam) : undefined),
  });
  useEffect(() => {
    if (!yearParam && entryQuery.data) navigate(`/acrl/entry/${entryQuery.data.selectedYear}`, { replace: true });
  }, [entryQuery.data, navigate, yearParam]);
  const entry = entryQuery.data as AcrlEntryResponse | undefined;
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['acrl-entry', String(entry?.selectedYear)] });

  return (
    <div className="mx-auto max-w-screen-2xl space-y-6 p-4 sm:p-6">
      <header className="space-y-1">
        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-folio-600">Annual survey entry</p>
        <h1 className="text-2xl font-semibold text-gray-900">ACRL section entry</h1>
        <p className="max-w-3xl text-sm leading-6 text-gray-600">Enter and review survey values. Year-over-year changes are advisory and use approved question links.</p>
      </header>
      {entryQuery.isLoading && <p role="status" className="text-sm text-gray-500">Loading published survey…</p>}
      {entryQuery.error && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">Could not load this published survey.</p>}
      {entry && <>
        <div className="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4">
          <label className="text-sm font-medium text-gray-700">Survey year
            <select className="mt-1 block rounded border border-gray-300 bg-white px-3 py-2" value={entry.selectedYear} onChange={(event) => navigate(`/acrl/entry/${event.target.value}`)}>
              {entry.years.map((year) => <option key={year} value={year}>{year}</option>)}
            </select>
          </label>
          <p className="text-sm text-gray-600">Changes at or above {entry.thresholdPercent}% are highlighted for review.</p>
        </div>
        {!entry.sections.length && <p className="rounded-lg border border-dashed border-gray-300 p-5 text-sm text-gray-500">This year has no published Survey questions.</p>}
        <div className="space-y-5">
          {entry.sections.map((section) => <SectionCard key={section.name} year={entry.selectedYear} section={section} isAdmin={isAdmin} onChanged={refresh} />)}
        </div>
      </>}
    </div>
  );
}
