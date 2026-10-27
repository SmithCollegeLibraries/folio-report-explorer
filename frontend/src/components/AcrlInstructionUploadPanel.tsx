import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getAcrlReview, saveAcrlInstructions } from '../api/acrl';
import { parseInstructionFile } from '../services/acrlInstructionParser';
import { sha256ForFile } from '../services/acrlWorksheetParser';
import type { AcrlInstructionParseResult, AcrlSurveyYear } from '../types/acrl';

export default function AcrlInstructionUploadPanel({ years }: { years: AcrlSurveyYear[] }) {
  const queryClient = useQueryClient();
  const [year, setYear] = useState<number | ''>('');
  const [file, setFile] = useState<File | null>(null);
  const [parsed, setParsed] = useState<AcrlInstructionParseResult | null>(null);
  const [error, setError] = useState('');
  const review = useQuery({ queryKey: ['acrl', 'review', year], queryFn: () => getAcrlReview(Number(year)), enabled: year !== '' });
  const questions = (review.data?.items || []).flatMap((item) => item.current?.sheet === 'survey' ? [{
    sheet: 'survey' as const,
    question_id: item.current.question_id,
    part: item.current.part,
    section: item.current.section,
  }] : []);
  const save = useMutation({
    mutationFn: async () => {
      if (!file || !parsed || year === '') throw new Error('Choose and parse an instructions PDF first.');
      const sha256 = await sha256ForFile(file);
      return saveAcrlInstructions(Number(year), {
        fileName: file.name,
        sha256,
        blocks: parsed.blocks.map((block) => ({ ...block, sourceFile: file.name, sourceSha256: sha256 })),
        links: parsed.links,
      });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['acrl-question-details'] });
      queryClient.invalidateQueries({ queryKey: ['acrl-entry'] });
    },
  });
  useEffect(() => {
    if (year === '' && years.length) setYear(years.find((item) => item.surveyYear === 2026)?.surveyYear ?? years[0].surveyYear);
  }, [year, years]);

  const readFile = async (selected: File | null) => {
    setFile(selected);
    setParsed(null);
    setError('');
    save.reset();
    if (!selected) return;
    if (selected.type !== 'application/pdf' && !selected.name.toLowerCase().endsWith('.pdf')) {
      setFile(null);
      setError('Choose an instructions PDF.');
      return;
    }
    try {
      setParsed(await parseInstructionFile(selected, questions));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Could not read this PDF.');
    }
  };

  return <section aria-labelledby="acrl-instructions-upload-title" className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
    <div>
      <p className="text-xs font-semibold uppercase tracking-[0.16em] text-folio-600">Administrator tools</p>
      <h2 id="acrl-instructions-upload-title" className="mt-1 text-lg font-semibold text-gray-900">Survey instructions</h2>
      <p className="mt-1 max-w-3xl text-sm leading-6 text-gray-600">Read the instructions PDF in this browser, review the question assignments, and save extracted text and links. The PDF itself is not uploaded.</p>
    </div>
    <div className="grid gap-3 sm:grid-cols-[10rem_minmax(16rem,1fr)] sm:items-end">
      <label className="text-sm font-medium text-gray-700">Survey year
        <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={year} onChange={(event) => { setYear(Number(event.target.value)); setParsed(null); }}>
          {years.map((item) => <option key={item.surveyYear} value={item.surveyYear}>{item.surveyYear}</option>)}
        </select>
      </label>
      <label className="text-sm font-medium text-gray-700">Instructions PDF
        <input className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" type="file" accept="application/pdf,.pdf" aria-label="Instructions PDF" disabled={year === '' || review.isLoading || questions.length === 0} onChange={(event) => void readFile(event.target.files?.[0] || null)} />
      </label>
    </div>
    {review.isLoading && <p role="status" className="text-sm text-gray-600">Loading question catalogue…</p>}
    {review.error && <p role="alert" className="text-sm text-rose-700">Could not load the selected year’s question catalogue.</p>}
    {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
    {parsed && <div className="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
      <h3 className="font-semibold text-gray-900">Instruction preview · {file?.name}</h3>
      <p role="status" className="text-sm text-gray-700">Coverage: {parsed.coverage.individual} individual · {parsed.coverage.grouped} grouped · {parsed.coverage.missing} missing</p>
      <p className="text-sm text-gray-700">{parsed.blocks.length} instruction blocks · {parsed.links.length} question links · {questions.length} Survey questions</p>
      {parsed.missingQuestionKeys.length > 0 && <p role="alert" className="text-sm text-rose-700">Questions without individual or grouped instructions: {parsed.missingQuestionKeys.join(', ')}</p>}
      {parsed.duplicateBlockKeys.length > 0 && <p role="alert" className="text-sm text-rose-700">Duplicate instruction block keys: {parsed.duplicateBlockKeys.join(', ')}. Review the PDF assignments before saving.</p>}
      <ul aria-label="Instruction blocks" className="max-h-64 space-y-1 overflow-auto text-sm text-gray-700">
        {parsed.blocks.map((block) => <li key={block.blockKey} className="rounded border border-gray-200 bg-white p-3">
          <p><span className="font-medium">{block.kind}: {block.heading}</span><span className="text-gray-500"> · {parsed.links.filter((link) => link.blockKey === block.blockKey).length} questions</span></p>
          <p className="mt-1 text-xs text-gray-500">Linked: {parsed.links.filter((link) => link.blockKey === block.blockKey).map((link) => `Q${link.question_id}${link.part}`).join(', ')}</p>
          <p className="mt-1 whitespace-pre-wrap text-xs leading-5 text-gray-600">{block.text}</p>
        </li>)}
      </ul>
      <button className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" type="button" disabled={!file || !parsed.links.length || parsed.duplicateBlockKeys.length > 0 || save.isPending} onClick={() => save.mutate()}>
        {save.isPending ? 'Saving instructions…' : 'Save instructions'}
      </button>
      {save.error && <p role="alert" className="text-sm text-rose-700">Could not save the instruction blocks.</p>}
      {save.data && <p role="status" className="text-sm text-emerald-800">Saved {save.data.blockCount} blocks and {save.data.linkCount} question links.</p>}
    </div>}
  </section>;
}
