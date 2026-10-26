import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import AcrlReviewPanel from '../components/AcrlReviewPanel';
import { getAcrlReview, listAcrlCatalogueYears, publishAcrlYear, saveAcrlLinks, uploadAcrlWorksheet } from '../api/acrl';
import { parseWorksheetFile, sha256ForFile } from '../services/acrlWorksheetParser';
import type { AcrlLinkDecisionInput, AcrlQuestionPart, AcrlWorksheetParseResult } from '../types/acrl';

function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  return 'The request could not be completed.';
}

export default function AcrlPage() {
  const queryClient = useQueryClient();
  const [surveyYear, setSurveyYear] = useState(String(new Date().getFullYear()));
  const [worksheet, setWorksheet] = useState<File | null>(null);
  const [parsed, setParsed] = useState<AcrlWorksheetParseResult | null>(null);
  const [parseError, setParseError] = useState('');
  const [replacePublished, setReplacePublished] = useState(false);
  const [reviewYear, setReviewYear] = useState<number | null>(null);

  const yearsQuery = useQuery({ queryKey: ['acrl', 'years'], queryFn: listAcrlCatalogueYears });
  const reviewQuery = useQuery({
    queryKey: ['acrl', 'review', reviewYear],
    queryFn: () => getAcrlReview(reviewYear as number),
    enabled: reviewYear !== null,
  });
  const uploadMutation = useMutation({
    mutationFn: async () => {
      if (!worksheet || !parsed) throw new Error('Choose and parse a worksheet PDF first.');
      return uploadAcrlWorksheet({
        surveyYear: Number(surveyYear),
        fileName: worksheet.name,
        sha256: await sha256ForFile(worksheet),
        items: parsed.items,
        replacePublished,
      });
    },
    onSuccess: (result) => {
      setReviewYear(result.surveyYear.surveyYear);
      queryClient.setQueryData(['acrl', 'review', result.surveyYear.surveyYear], result.review);
      queryClient.invalidateQueries({ queryKey: ['acrl', 'years'] });
      queryClient.invalidateQueries({ queryKey: ['local', 'acrl-years'] });
    },
  });
  const saveMutation = useMutation({
    mutationFn: (links: AcrlLinkDecisionInput[]) => {
      if (reviewYear === null) throw new Error('Select a year to review first.');
      return saveAcrlLinks(reviewYear, links);
    },
    onSuccess: (review) => queryClient.setQueryData(['acrl', 'review', review.surveyYear], review),
  });
  const publishMutation = useMutation({
    mutationFn: () => {
      if (reviewYear === null) throw new Error('Select a year to review first.');
      return publishAcrlYear(reviewYear);
    },
    onSuccess: (review) => {
      queryClient.setQueryData(['acrl', 'review', review.surveyYear], review);
      queryClient.invalidateQueries({ queryKey: ['acrl', 'years'] });
    },
  });

  const handleFile = async (file: File | null) => {
    setWorksheet(file);
    setParsed(null);
    setParseError('');
    uploadMutation.reset();
    if (!file) return;
    try {
      setParsed(await parseWorksheetFile(file));
    } catch (error) {
      setParseError(errorMessage(error));
    }
  };

  const itemCounts = parsed?.items.reduce((counts, item) => {
    counts[item.sheet] += 1;
    return counts;
  }, { survey: 0, trends: 0 });
  const uploadError = uploadMutation.error ? errorMessage(uploadMutation.error) : '';
  const reviewError = saveMutation.error || publishMutation.error;
  const previewReady = Boolean(parsed && parsed.items.length && !parsed.unresolved.length);

  return (
    <div className="mx-auto max-w-screen-2xl space-y-6 p-4 sm:p-6">
      <header className="space-y-1">
        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-folio-600">Annual question catalogue</p>
        <h1 className="text-2xl font-semibold text-gray-900">ACRL worksheet review</h1>
        <p className="max-w-3xl text-sm leading-6 text-gray-600">Upload a worksheet PDF to preview the question parts, compare them with the previous year, and publish the reviewed catalogue. The PDF is read in this browser and only the extracted question text is saved.</p>
      </header>

      <section aria-labelledby="upload-heading" className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div>
          <h2 id="upload-heading" className="text-lg font-semibold text-gray-900">Upload worksheet</h2>
          <p className="mt-1 text-sm text-gray-600">PDF worksheets are parsed locally before you save a draft.</p>
        </div>
        <div className="grid gap-4 md:grid-cols-[10rem_minmax(16rem,1fr)_auto] md:items-end">
          <label className="text-sm font-medium text-gray-700">Survey year
            <input className="mt-1 block w-full rounded border border-gray-300 px-3 py-2" type="number" min="1900" max="2100" value={surveyYear} onChange={(event) => setSurveyYear(event.target.value)} />
          </label>
          <label className="text-sm font-medium text-gray-700">Worksheet PDF
            <input className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2 file:mr-3 file:rounded file:border-0 file:bg-folio-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-folio-800" type="file" accept="application/pdf,.pdf" aria-label="Worksheet PDF" onChange={(event) => void handleFile(event.target.files?.[0] || null)} />
          </label>
          <button className="rounded bg-folio-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-folio-800 disabled:cursor-not-allowed disabled:opacity-50" disabled={!previewReady || uploadMutation.isPending || !surveyYear} onClick={() => uploadMutation.mutate()}>
            {uploadMutation.isPending ? 'Saving draft…' : 'Save draft'}
          </button>
        </div>
        {yearsQuery.data?.some((year) => year.surveyYear === Number(surveyYear) && year.status === 'published') && (
          <label className="flex items-center gap-2 text-sm text-amber-900">
            <input type="checkbox" checked={replacePublished} onChange={(event) => setReplacePublished(event.target.checked)} />
            Replace this published year with a draft. Existing decisions are kept for question parts that remain.
          </label>
        )}
        {parseError && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">Could not parse worksheet: {parseError}</p>}
        {uploadError && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">Could not save worksheet: {uploadError}</p>}
        {parsed && (
          <div className="space-y-3 border-t border-gray-100 pt-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 className="font-semibold text-gray-900">Parsed worksheet preview</h3>
                <p className="text-sm text-gray-600">{itemCounts?.survey || 0} Survey parts · {itemCounts?.trends || 0} Trends parts · {parsed.unresolved.length} unresolved lines</p>
              </div>
              {parsed.unresolved.length > 0 && <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">Resolve these lines before saving</span>}
            </div>
            {parsed.unresolved.length > 0 && <ul className="list-disc space-y-1 rounded border border-amber-200 bg-amber-50 p-4 pl-8 text-sm text-amber-950">{parsed.unresolved.map((line, index) => <li key={`${line}-${index}`}>{line}</li>)}</ul>}
            <div className="max-h-96 overflow-auto rounded border border-gray-200">
              <table className="min-w-full text-left text-sm">
                <thead className="sticky top-0 bg-gray-50 text-xs uppercase tracking-wide text-gray-500"><tr><th className="px-3 py-2">Sheet</th><th className="px-3 py-2">Question</th><th className="px-3 py-2">Section</th><th className="px-3 py-2">Prompt</th><th className="px-3 py-2">Required</th><th className="px-3 py-2">Calculated from</th></tr></thead>
                <tbody className="divide-y divide-gray-100">{parsed.items.map((item: AcrlQuestionPart) => <tr key={`${item.sheet}-${item.question_id}-${item.part}`}><td className="px-3 py-2">{item.sheet}</td><td className="whitespace-nowrap px-3 py-2 font-medium">{item.question_id}{item.part}</td><td className="px-3 py-2">{item.section}</td><td className="min-w-72 px-3 py-2">{item.prompt}</td><td className="px-3 py-2">{item.required}</td><td className="min-w-48 px-3 py-2">{item.calculated_from || '—'}</td></tr>)}</tbody>
              </table>
            </div>
          </div>
        )}
      </section>

      <section aria-labelledby="years-heading" className="space-y-3">
        <div className="flex items-center justify-between gap-2">
          <div>
            <h2 id="years-heading" className="text-lg font-semibold text-gray-900">Survey years</h2>
            <p className="text-sm text-gray-600">Choose a year to inspect the adjacent-year review.</p>
          </div>
          {yearsQuery.isFetching && <span className="text-xs text-gray-500">Refreshing…</span>}
        </div>
        {yearsQuery.error && <p role="alert" className="text-sm text-rose-700">Could not load survey years: {errorMessage(yearsQuery.error)}</p>}
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {(yearsQuery.data || []).map((year) => (
            <article key={year.surveyYear} className={`rounded-lg border p-4 ${reviewYear === year.surveyYear ? 'border-folio-500 ring-1 ring-folio-300' : 'border-gray-200 bg-white'}`}>
              <div className="flex items-start justify-between gap-3">
                <div><h3 className="text-lg font-semibold text-gray-900">FY {year.surveyYear}</h3><p className="mt-1 text-sm text-gray-600">{year.surveyPromptCount} Survey prompts · {year.trendsPromptCount} Trends prompts</p><p className="text-xs text-gray-500">{year.totalItemCount} question parts</p></div>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${year.status === 'published' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'}`}>{year.status}</span>
              </div>
              <button className="mt-4 rounded border border-folio-700 px-3 py-1.5 text-sm font-medium text-folio-800 hover:bg-folio-50" onClick={() => setReviewYear(year.surveyYear)}>Review {year.surveyYear}</button>
            </article>
          ))}
          {!yearsQuery.isLoading && !yearsQuery.data?.length && <p className="rounded-lg border border-dashed border-gray-300 p-5 text-sm text-gray-500">No question catalogues are available yet.</p>}
        </div>
      </section>

      {reviewYear !== null && (
        <section className="space-y-4 border-t border-gray-200 pt-6">
          {reviewQuery.isLoading && <p className="text-sm text-gray-500">Loading annual review…</p>}
          {reviewQuery.error && <p role="alert" className="text-sm text-rose-700">Could not load review: {errorMessage(reviewQuery.error)}</p>}
          {reviewError && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">{errorMessage(reviewError)}</p>}
          {reviewQuery.data && <AcrlReviewPanel review={reviewQuery.data} saving={saveMutation.isPending} publishing={publishMutation.isPending} onSave={(links) => saveMutation.mutate(links)} onPublish={() => publishMutation.mutate()} />}
        </section>
      )}
    </div>
  );
}
