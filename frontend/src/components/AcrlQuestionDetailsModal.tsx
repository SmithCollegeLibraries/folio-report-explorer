import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { addAcrlQuestionNote, deleteAcrlQuestionNote, editAcrlQuestionNote, getAcrlQuestionDetails } from '../api/acrl';
import type { AcrlEntryQuestion, AcrlQuestionNote } from '../types/acrl';

interface Props {
  year: number;
  question: AcrlEntryQuestion;
  isAdmin: boolean;
  onClose: () => void;
}

export default function AcrlQuestionDetailsModal({ year, question, isAdmin, onClose }: Props) {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<'instructions' | 'notes'>('instructions');
  const [noteText, setNoteText] = useState('');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editText, setEditText] = useState('');
  const [editSource, setEditSource] = useState('');
  const [editYear, setEditYear] = useState(String(year));
  const dialogRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const closeHandler = useRef(onClose);
  const queryKey = ['acrl-question-details', year, question.sheet, question.questionId, question.part];
  const detailsQuery = useQuery({
    queryKey,
    queryFn: () => getAcrlQuestionDetails(year, question.sheet, question.questionId, question.part),
  });

  useEffect(() => { closeHandler.current = onClose; }, [onClose]);
  useEffect(() => {
    const priorFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    closeRef.current?.focus();
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeHandler.current();
        return;
      }
      if (event.key !== 'Tab' || !dialogRef.current) return;
      const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>(
        'button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'
      ));
      if (!focusable.length) {
        event.preventDefault();
        dialogRef.current.focus();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !dialogRef.current.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || !dialogRef.current.contains(active))) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      priorFocus?.focus();
    };
  }, []);

  const refreshDetails = () => {
    queryClient.invalidateQueries({ queryKey });
    queryClient.invalidateQueries({ queryKey: ['acrl-entry', String(year)] });
  };
  const addMutation = useMutation({
    mutationFn: () => addAcrlQuestionNote(year, {
      sheet: question.sheet,
      question_id: question.questionId,
      part: question.part,
      note: noteText,
      source: 'How I collected this',
      origin_year: year,
    }),
    onSuccess: () => { setNoteText(''); refreshDetails(); },
  });
  const editMutation = useMutation({
    mutationFn: () => {
      if (editingId === null) throw new Error('Select a note to edit.');
      return editAcrlQuestionNote(editingId, { note: editText, source: editSource, origin_year: Number(editYear) });
    },
    onSuccess: () => { setEditingId(null); refreshDetails(); },
  });
  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteAcrlQuestionNote(id),
    onSuccess: refreshDetails,
  });
  const questionLabel = `Q${question.questionId}${question.part ? ` ${question.part}` : ''}`;
  const notes = detailsQuery.data?.notes || [];

  const beginEdit = (item: AcrlQuestionNote) => {
    setEditingId(item.id);
    setEditText(item.note);
    setEditSource(item.source);
    setEditYear(String(item.origin_year));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/45 p-3 sm:p-6" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}>
      <section ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="acrl-question-details-title" tabIndex={-1}
        className="flex max-h-[min(88vh,52rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-300 bg-white shadow-2xl">
        <header className="flex items-start justify-between gap-4 border-b border-gray-200 bg-gray-50 px-5 py-4 sm:px-6">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-folio-600">FY{year} · {question.section}</p>
            <h2 id="acrl-question-details-title" className="mt-1 text-xl font-semibold text-gray-950">Question details</h2>
            <p className="mt-1 text-sm text-gray-700">{questionLabel} · {question.prompt}</p>
          </div>
          <button ref={closeRef} type="button" aria-label="Close details" onClick={onClose}
            className="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-folio-500">Close</button>
        </header>

        <div role="tablist" aria-label="Question details sections" className="flex gap-2 border-b border-gray-200 px-5 pt-3 sm:px-6">
          <button type="button" role="tab" id="acrl-details-tab-instructions" aria-controls="acrl-details-panel-instructions" aria-selected={tab === 'instructions'} tabIndex={tab === 'instructions' ? 0 : -1}
            onClick={() => setTab('instructions')} className={`border-b-2 px-2 pb-3 text-sm font-semibold ${tab === 'instructions' ? 'border-folio-700 text-folio-900' : 'border-transparent text-gray-600 hover:text-gray-900'}`}>Instructions</button>
          <button type="button" role="tab" id="acrl-details-tab-notes" aria-controls="acrl-details-panel-notes" aria-selected={tab === 'notes'} tabIndex={tab === 'notes' ? 0 : -1}
            onClick={() => setTab('notes')} className={`border-b-2 px-2 pb-3 text-sm font-semibold ${tab === 'notes' ? 'border-folio-700 text-folio-900' : 'border-transparent text-gray-600 hover:text-gray-900'}`}>How we collected this</button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6">
          {detailsQuery.isLoading && <p role="status" className="text-sm text-gray-500">Loading question details…</p>}
          {detailsQuery.error && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">Could not load question details.</p>}
          {tab === 'instructions' && detailsQuery.data && <div id="acrl-details-panel-instructions" role="tabpanel" aria-labelledby="acrl-details-tab-instructions" className="space-y-4">
            {detailsQuery.data.instructions.length === 0 && <p className="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600">No instruction text is saved for this question.</p>}
            {detailsQuery.data.instructions.map((block) => <article key={block.blockKey} className="rounded-lg border border-gray-200 bg-white p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">{block.kind === 'question' ? 'Question instruction' : block.kind === 'group' ? 'Shared instruction' : 'Shared definition'}</p>
              <h3 className="mt-1 font-semibold text-gray-900">{block.heading}</h3>
              <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{block.text}</p>
              {block.sourceFile && <p className="mt-3 text-xs text-gray-500">Source: {block.sourceFile}</p>}
            </article>)}
          </div>}

          {tab === 'notes' && detailsQuery.data && <div id="acrl-details-panel-notes" role="tabpanel" aria-labelledby="acrl-details-tab-notes" className="space-y-4">
            {notes.length === 0 && <p className="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600">No collection notes yet.</p>}
            {notes.map((item) => <article key={item.id} className="rounded-lg border border-gray-200 bg-gray-50 p-4">
              {editingId === item.id ? <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); editMutation.mutate(); }}>
                <label className="block text-sm font-medium text-gray-700">Edit note
                  <textarea className="mt-1 block min-h-24 w-full rounded border border-gray-300 px-3 py-2" value={editText} onChange={(event) => setEditText(event.target.value)} required />
                </label>
                <div className="grid gap-3 sm:grid-cols-[1fr_9rem]">
                  <label className="text-sm font-medium text-gray-700">Note source
                    <input className="mt-1 block w-full rounded border border-gray-300 px-3 py-2" value={editSource} onChange={(event) => setEditSource(event.target.value)} required />
                  </label>
                  <label className="text-sm font-medium text-gray-700">Note year
                    <input className="mt-1 block w-full rounded border border-gray-300 px-3 py-2" type="number" min="1900" max="2100" value={editYear} onChange={(event) => setEditYear(event.target.value)} required />
                  </label>
                </div>
                <div className="flex gap-2">
                  <button className="rounded bg-folio-700 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50" disabled={editMutation.isPending} type="submit">Save changes</button>
                  <button className="rounded border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700" type="button" onClick={() => setEditingId(null)}>Cancel</button>
                </div>
              </form> : <>
                <p className="whitespace-pre-wrap text-sm leading-6 text-gray-800">{item.note}</p>
                <p className="mt-3 text-xs font-medium text-gray-600">{item.source} · FY{item.origin_year}</p>
                {item.created_at && <p className="mt-1 text-xs text-gray-500">Recorded {item.created_at}</p>}
                {isAdmin && <div className="mt-3 flex gap-2 border-t border-gray-200 pt-3">
                  <button type="button" className="rounded border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-white" onClick={() => beginEdit(item)}>Edit note</button>
                  <button type="button" className="rounded border border-rose-300 px-2.5 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-50" onClick={() => {
                    if (window.confirm('Remove this collection note?')) deleteMutation.mutate(item.id);
                  }}>Remove note</button>
                </div>}
              </>}
            </article>)}

            {detailsQuery.data.canAddNote && <form className="space-y-3 rounded-lg border border-folio-200 bg-folio-50/50 p-4" onSubmit={(event) => { event.preventDefault(); addMutation.mutate(); }}>
              <div>
                <h3 className="font-semibold text-gray-900">Add a collection note</h3>
                <p className="mt-1 text-xs text-gray-600">How I collected this · FY{year}</p>
              </div>
              <label className="block text-sm font-medium text-gray-700">Collection note
                <textarea className="mt-1 block min-h-24 w-full rounded border border-gray-300 bg-white px-3 py-2" value={noteText} onChange={(event) => setNoteText(event.target.value)} required />
              </label>
              <button className="rounded bg-folio-700 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50" type="submit" disabled={!noteText.trim() || addMutation.isPending}>{addMutation.isPending ? 'Adding…' : 'Add note'}</button>
            </form>}
            {(addMutation.error || editMutation.error || deleteMutation.error) && <p role="alert" className="text-sm text-rose-700">Could not save this collection note.</p>}
          </div>}
        </div>
      </section>
    </div>
  );
}
