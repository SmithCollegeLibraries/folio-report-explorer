import { useState, type FormEvent } from 'react';
import type { AcrlEntryQuestion, AcrlInviteActionResponse, AcrlInviteText, AcrlInviteTargetInput } from '../types/acrl';
import { acrlQuestionAssignmentKey, acrlQuestionLabel } from '../utils/acrlAssignments';
import { useAcrlInvites } from '../hooks/useAcrlInvites';

type AcrlInvitePanelProps = {
  year: number;
  section: string;
  questions: AcrlEntryQuestion[];
  selectedQuestionKey: string;
};

function responseError(error: unknown): { message: string; userName: string | null } {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { error?: unknown; user?: { displayName?: unknown } } } }).response;
    const data = response?.data;
    return {
      message: typeof data?.error === 'string' ? data.error : 'Could not send the invite.',
      userName: typeof data?.user?.displayName === 'string' ? data.user.displayName : null,
    };
  }
  return { message: 'Could not send the invite.', userName: null };
}

function inviteTextContent(inviteText: AcrlInviteText): string {
  return `${inviteText.subject}\n\n${inviteText.body}`;
}

function targetLabel(target: { label: string; section: string; kind: string; questionId?: string; part?: string }): string {
  if (target.label) return target.label;
  if (target.kind === 'question' && target.questionId) return acrlQuestionLabel(target.questionId, target.part || '');
  return target.section;
}

function InviteText({ inviteText }: { inviteText: AcrlInviteText }) {
  return <div className="space-y-1 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-gray-800">
    <p className="font-semibold">{inviteText.subject}</p>
    <pre className="whitespace-pre-wrap font-sans">{inviteText.body}</pre>
  </div>;
}

export default function AcrlInvitePanel({ year, section, questions, selectedQuestionKey }: AcrlInvitePanelProps) {
  const { query, create, resend, revoke } = useAcrlInvites(year, Boolean(section));
  const [email, setEmail] = useState('');
  const [result, setResult] = useState<AcrlInviteActionResponse | null>(null);
  const [error, setError] = useState<{ message: string; userName: string | null } | null>(null);
  const [actionMessage, setActionMessage] = useState('');
  const [actionError, setActionError] = useState('');

  const selectedQuestion = questions.find((question) => acrlQuestionAssignmentKey(question.sheet, question.questionId, question.part) === selectedQuestionKey);
  const inviteTarget: AcrlInviteTargetInput = selectedQuestion
    ? { kind: 'question', sheet: selectedQuestion.sheet, questionId: selectedQuestion.questionId, part: selectedQuestion.part }
    : { kind: 'section', section };
  const inviteTargetLabel = selectedQuestion
    ? acrlQuestionLabel(selectedQuestion.questionId, selectedQuestion.part)
    : section;

  async function copyInviteText(inviteText: AcrlInviteText) {
    try {
      await navigator.clipboard.writeText(inviteTextContent(inviteText));
    } catch {
      setActionError('Could not copy invite text.');
    }
  }

  async function submitInvite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setResult(null);
    setError(null);
    setActionMessage('');
    try {
      const response = await create.mutateAsync({ email: email.trim(), targets: [inviteTarget] });
      setResult(response);
      setEmail('');
    } catch (caught) {
      setError(responseError(caught));
    }
  }

  async function resendInvite(id: number) {
    setActionMessage('');
    setActionError('');
    setResult(null);
    try {
      const response = await resend.mutateAsync(id);
      setResult(response);
      if (response.emailSent) setActionMessage(`Invite emailed to ${response.invite.email}`);
    } catch (caught) {
      setActionError(responseError(caught).message);
    }
  }

  async function revokeInvite(id: number) {
    setActionMessage('');
    setActionError('');
    try {
      await revoke.mutateAsync(id);
      setActionMessage('Invite revoked.');
    } catch (caught) {
      setActionError(responseError(caught).message);
    }
  }

  return <section aria-label="Invitations" className="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
    <div>
      <h3 className="text-base font-semibold text-gray-900">Invite someone who isn't in the app</h3>
      <p className="mt-1 text-sm text-gray-600">Use their Smith username address (username@&lt;domain&gt;).</p>
    </div>
    <p className="text-sm font-medium text-gray-800">Invite to {inviteTargetLabel}</p>
    <p className="text-sm text-gray-600">Invited people get the same app access as anyone approved on the Users page.</p>
    <form className="flex flex-col gap-3 sm:flex-row sm:items-end" onSubmit={submitInvite}>
      <label className="block flex-1 text-sm font-medium text-gray-700">Email address
        <input className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" type="email" autoComplete="email" required value={email} onChange={(event) => setEmail(event.target.value)} />
      </label>
      <button className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white hover:bg-folio-800 disabled:opacity-50" type="submit" disabled={create.isPending || !section}>
        {create.isPending ? 'Sending…' : 'Send invite'}
      </button>
    </form>
    {error && <div role="alert" className="space-y-1 text-sm text-rose-700"><p>{error.message}</p>{error.userName && <p>{error.userName}</p>}</div>}
    {result?.emailSent === true && <p role="status" className="text-sm text-emerald-800">Invite emailed to {result.invite.email}</p>}
    {result?.emailSent === false && result.inviteText && <div className="space-y-2">
      <p role="alert" className="text-sm text-amber-800">Email could not be sent - copy the invite below</p>
      <InviteText inviteText={result.inviteText} />
      <button className="rounded border border-folio-700 px-3 py-2 text-sm font-semibold text-folio-800 hover:bg-folio-50" type="button" onClick={() => void copyInviteText(result.inviteText!)}>Copy invite text</button>
    </div>}
    {actionMessage && <p role="status" className="text-sm text-emerald-800">{actionMessage}</p>}
    {actionError && <p role="alert" className="text-sm text-rose-700">{actionError}</p>}
    <div className="space-y-3 border-t border-gray-200 pt-3">
      {query.isLoading && <p role="status" className="text-sm text-gray-500">Loading invites…</p>}
      {!query.isLoading && <h4 className="font-semibold text-gray-900">This year's invites</h4>}
      {query.error && <p role="alert" className="text-sm text-rose-700">Could not load invites.</p>}
      {query.data?.invites.length === 0 && <p className="text-sm text-gray-600">No invites for this year.</p>}
      {query.data?.invites.map((invite) => <article key={invite.id} className="space-y-2 rounded border border-gray-200 p-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="font-medium text-gray-900">{invite.email}</p>
            <p className="text-sm text-gray-600">Status: <span>{invite.status}</span></p>
          </div>
          <div className="text-sm text-gray-600">Last sent: {invite.lastSentAt ? <time>{invite.lastSentAt}</time> : 'Never'}</div>
        </div>
        <ul className="list-inside list-disc text-sm text-gray-700">
          {invite.targets.map((target, index) => <li key={`${invite.id}-${index}`}>{targetLabel(target)}</li>)}
        </ul>
        {invite.status === 'pending' && <div className="flex flex-wrap gap-2">
          <button type="button" className="rounded border border-folio-700 px-3 py-1.5 text-sm font-semibold text-folio-800 hover:bg-folio-50 disabled:opacity-50" aria-label={`Resend invite to ${invite.email}`} disabled={resend.isPending} onClick={() => void resendInvite(invite.id)}>Resend</button>
          <button type="button" className="rounded border border-rose-700 px-3 py-1.5 text-sm font-semibold text-rose-800 hover:bg-rose-50 disabled:opacity-50" aria-label={`Revoke invite to ${invite.email}`} disabled={revoke.isPending} onClick={() => void revokeInvite(invite.id)}>Revoke</button>
          {invite.inviteText && <button type="button" className="rounded border border-gray-400 px-3 py-1.5 text-sm font-semibold text-gray-800 hover:bg-gray-50" aria-label={`Copy invite text for ${invite.email}`} onClick={() => void copyInviteText(invite.inviteText!)}>Copy text</button>}
        </div>}
      </article>)}
    </div>
  </section>;
}
