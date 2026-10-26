import type { AcrlQuestionPart } from '../types/acrl';

function sharedWordCounts(text: string): Map<string, number> {
  const counts = new Map<string, number>();
  for (const word of text.match(/[\p{L}\p{N}]+/gu) || []) {
    const key = word.toLocaleLowerCase();
    counts.set(key, (counts.get(key) || 0) + 1);
  }
  return counts;
}

function markedPrompt(prompt: string, otherPrompt: string, kind: 'previous' | 'current') {
  const sharedCounts = sharedWordCounts(otherPrompt);
  return (prompt.match(/\s+|[^\s]+/gu) || []).map((token, index) => {
    const words = token.match(/[\p{L}\p{N}]+/gu) || [];
    const changed = words.some((word) => {
      const key = word.toLocaleLowerCase();
      const remaining = sharedCounts.get(key) || 0;
      if (remaining > 0) {
        sharedCounts.set(key, remaining - 1);
        return false;
      }
      return true;
    });
    if (!changed) return token;
    return <mark className={kind === 'previous' ? 'rounded bg-rose-100 text-rose-950' : 'rounded bg-emerald-100 text-emerald-950'} key={`${kind}-${index}`}>{token}</mark>;
  });
}

function questionLabel(question: AcrlQuestionPart | null): string {
  if (!question) return '—';
  return `${question.question_id}${question.part}`;
}

export default function AcrlPromptComparison({ previous, current }: { previous: AcrlQuestionPart | null; current: AcrlQuestionPart | null }) {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <div className="rounded border border-gray-200 bg-gray-50 p-3">
        <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Previous · {questionLabel(previous)}</div>
        <p className="whitespace-pre-wrap text-sm leading-6">{previous ? markedPrompt(previous.prompt, current?.prompt || '', 'previous') : 'No previous question'}</p>
      </div>
      <div className="rounded border border-gray-200 bg-white p-3">
        <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Current · {questionLabel(current)}</div>
        <p className="whitespace-pre-wrap text-sm leading-6">{current ? markedPrompt(current.prompt, previous?.prompt || '', 'current') : 'Question removed'}</p>
      </div>
    </div>
  );
}
