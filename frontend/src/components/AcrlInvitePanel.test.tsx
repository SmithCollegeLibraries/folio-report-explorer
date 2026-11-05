import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, it, vi } from 'vitest';
import AcrlSectionAssignmentPanel from './AcrlSectionAssignmentPanel';
import type { AcrlEntrySection } from '../types/acrl';

const { getAssignments, getInvites, createInvite, resendInvite, revokeInvite, saveAssignments, searchUsers, copyAssignments, saveQuestionAssignments } = vi.hoisted(() => ({
  getAssignments: vi.fn(),
  getInvites: vi.fn(),
  createInvite: vi.fn(),
  resendInvite: vi.fn(),
  revokeInvite: vi.fn(),
  saveAssignments: vi.fn(),
  searchUsers: vi.fn(),
  copyAssignments: vi.fn(),
  saveQuestionAssignments: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlSectionAssignments: getAssignments,
  getAcrlInvites: getInvites,
  createAcrlInvite: createInvite,
  resendAcrlInvite: resendInvite,
  revokeAcrlInvite: revokeInvite,
  saveAcrlSectionAssignments: saveAssignments,
  searchAcrlApprovedUsers: searchUsers,
  saveAcrlQuestionAssignments: saveQuestionAssignments,
  copyAcrlAssignmentsFromPrevious: copyAssignments,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const section: AcrlEntrySection = {
  name: 'Circulation', status: 'open', setAt: null, reopenedAt: null, filled: 0, total: 1,
  assignees: [], assignedToCurrentUser: false, questionsAssignedToCurrentUser: 0,
  questions: [{
    sheet: 'survey', questionId: '60', part: 'A', section: 'Circulation', prompt: 'Physical circulation',
    answerType: 'number', required: 'yes', calculatedFrom: null, surveyOrder: 1, hasDetailsNotes: false,
    questionAssignees: [], assignees: [], assignedToCurrentUser: false, questionAssignedToCurrentUser: false,
    answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    previous: { value: null, label: 'No previous value', isSum: false, linkedValues: [] }, history: [], suggestion: null,
    comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false }, calculated: null,
  }],
};

const pendingInvite = {
  id: 31,
  email: 'reader@smith.example',
  surveyYear: 2026,
  status: 'pending',
  targets: [{ kind: 'question', section: 'Circulation', sheet: 'survey', questionId: '60', part: 'A', label: 'Q60 · A' }],
  invitedBy: { id: 9, displayName: 'Synthetic Admin' },
  createdAt: '2026-11-05 10:00:00',
  expiresAt: '2027-02-03 10:00:00',
  lastSentAt: '2026-11-05 10:00:00',
  sendCount: 1,
  acceptedAt: null,
};
const inviteText = {
  subject: "[FOLIO Report Explorer] You're invited to help with the ACRL survey FY 2026",
  body: 'Invited by Synthetic Admin\nQ60 · A\nhttps://reports.example.test/acrl/entry/2026\nSign in with your Smith login, then open this link again.\nExpires 2027-02-03',
};

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AcrlSectionAssignmentPanel year={2026} sections={[section]} /></QueryClientProvider>);
}

function collectFailures() {
  const failures: string[] = [];
  return {
    failures,
    check(condition: boolean, message: string) { if (!condition) failures.push(message); },
    finish() { if (failures.length) throw new Error(failures.join('; ')); },
  };
}

async function settle(condition: () => boolean): Promise<boolean> {
  try {
    await waitFor(() => {
      if (!condition()) throw new Error('not ready');
    }, { timeout: 800 });
    return true;
  } catch {
    return false;
  }
}

describe('ACRL invite panel', () => {
  it('submits a question invite and reports successful email delivery', async () => {
    const check = collectFailures();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }], questions: [] });
    getInvites.mockResolvedValue({ invites: [], pendingCount: 0 });
    createInvite.mockImplementation(async (year: number, input: { email?: string; targets?: unknown[] }) => {
      const expected = { email: 'reader@smith.example', targets: [{ kind: 'question', sheet: 'survey', questionId: '60', part: 'A' }] };
      if (year !== 2026 || JSON.stringify(input) !== JSON.stringify(expected)) throw new Error('unexpected invite request');
      return { invite: pendingInvite, emailSent: true, inviteText };
    });
    renderPanel();

    check.check(screen.queryByText("Invite someone who isn't in the app") !== null, 'The panel offers the invite form.');
    check.check(screen.queryByText('Use their Smith username address (username@<domain>).') !== null, 'The form advises the username@domain address.');
    check.check(screen.queryByText('Invited people get the same app access as anyone approved on the Users page.') !== null, 'The panel explains invitees receive the normal approved-user access.');
    const questionPicker = screen.queryByLabelText('Question');
    if (questionPicker) {
      const option = Array.from(questionPicker.querySelectorAll('option')).find((item) => item.textContent?.includes('Q60 · A'));
      if (option) await userEvent.selectOptions(questionPicker, option);
    }
    const email = screen.queryByLabelText('Email address');
    const submit = screen.queryByRole('button', { name: 'Send invite' });
    if (email) await userEvent.type(email, 'reader@smith.example');
    if (submit) await userEvent.click(submit);
    check.check(await settle(() => createInvite.mock.calls.length === 1), 'Submitting the invite calls the create API.');
    check.check(createInvite.mock.calls[0]?.[0] === 2026, 'Invite API request uses the selected survey year.');
    check.check(JSON.stringify(createInvite.mock.calls[0]?.[1]) === JSON.stringify({
      email: 'reader@smith.example', targets: [{ kind: 'question', sheet: 'survey', questionId: '60', part: 'A' }],
    }), 'Invite API request sends the selected full question tuple and no extra target fields.');
    check.check(screen.queryByText('Invite emailed to reader@smith.example') !== null, 'Successful delivery names the recipient in the result.');
    check.check(screen.queryByText(inviteText.subject) !== null, 'Successful delivery also shows the generated subject.');
    const copyBox = screen.queryByText(inviteText.subject)?.parentElement;
    check.check(copyBox?.querySelector('pre')?.textContent === inviteText.body, 'Successful delivery also shows the complete plain-text body.');
    const copy = screen.queryByRole('button', { name: 'Copy invite text' });
    if (copy) await userEvent.click(copy);
    check.check(await settle(() => writeText.mock.calls.length === 1), 'Successful delivery exposes a copy action.');
    check.check(writeText.mock.calls[0]?.[0] === inviteText.subject + '\n\n' + inviteText.body, 'Successful delivery copies the exact subject and body.');
    check.finish();
  });

  it('submits the current section as a section invite target', async () => {
    const check = collectFailures();
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }], questions: [] });
    getInvites.mockResolvedValue({ invites: [], pendingCount: 0 });
    createInvite.mockImplementation(async (year: number, input: { email?: string; targets?: unknown[] }) => {
      const expected = { email: 'section-reader@smith.example', targets: [{ kind: 'section', section: 'Circulation' }] };
      if (year !== 2026 || JSON.stringify(input) !== JSON.stringify(expected)) throw new Error('unexpected section invite request');
      return { invite: pendingInvite, emailSent: true, inviteText };
    });
    renderPanel();
    check.check(screen.queryByText('Invite to Circulation') !== null, 'Whole-section selection is the default invite target.');
    const email = screen.queryByLabelText('Email address');
    const submit = screen.queryByRole('button', { name: 'Send invite' });
    if (email) await userEvent.type(email, 'section-reader@smith.example');
    if (submit) await userEvent.click(submit);
    check.check(await settle(() => createInvite.mock.calls.length === 1), 'Section invite reaches the create API.');
    check.check(JSON.stringify(createInvite.mock.calls[0]?.[1]) === JSON.stringify({
      email: 'section-reader@smith.example', targets: [{ kind: 'section', section: 'Circulation' }],
    }), 'Section invite sends exactly the selected section target.');
    check.finish();
  });

  it('shows copy text when delivery fails and copies the exact subject and body', async () => {
    const check = collectFailures();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }], questions: [] });
    getInvites.mockResolvedValue({ invites: [], pendingCount: 0 });
    createInvite.mockImplementation(async (year: number, input: { email?: string; targets?: unknown[] }) => {
      if (year !== 2026 || input.email !== 'reader@smith.example' || !Array.isArray(input.targets) || input.targets.length !== 1) throw new Error('unexpected invite request');
      return { invite: pendingInvite, emailSent: false, inviteText };
    });
    renderPanel();
    const email = screen.queryByLabelText('Email address');
    const submit = screen.queryByRole('button', { name: 'Send invite' });
    if (email) await userEvent.type(email, 'reader@smith.example');
    if (submit) await userEvent.click(submit);
    check.check(await settle(() => screen.queryByText('Email could not be sent - copy the invite below') !== null), 'Mail failure presents the exact copy fallback.');
    check.check(screen.queryByText(inviteText.subject) !== null, 'Copy fallback shows the generated subject.');
    const copyBox = screen.queryByText(inviteText.subject)?.parentElement;
    check.check(copyBox?.querySelector('pre')?.textContent === inviteText.body, 'Copy fallback shows the complete plain-text body.');
    const copy = screen.queryByRole('button', { name: 'Copy invite text' });
    if (copy) await userEvent.click(copy);
    check.check(await settle(() => writeText.mock.calls.length === 1), 'Copy action writes to the clipboard.');
    check.check(writeText.mock.calls[0]?.[0] === inviteText.subject + '\n\n' + inviteText.body, 'Clipboard text preserves the subject/body content.');
    check.finish();
  });

  it('shows existing-account 409 guidance and the matched user name', async () => {
    const check = collectFailures();
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }], questions: [] });
    getInvites.mockResolvedValue({ invites: [], pendingCount: 0 });
    createInvite.mockImplementation(async (year: number, input: { email?: string }) => {
      if (year !== 2026 || input.email !== 'existing@smith.example') throw new Error('unexpected conflict request');
      throw { response: { status: 409, data: { error: 'Already in the app - assign them directly.', user: { id: 8, displayName: 'Synthetic Existing' } } } };
    });
    renderPanel();
    const email = screen.queryByLabelText('Email address');
    const submit = screen.queryByRole('button', { name: 'Send invite' });
    if (email) await userEvent.type(email, 'existing@smith.example');
    if (submit) await userEvent.click(submit);
    check.check(await settle(() => screen.queryByText('Already in the app - assign them directly.') !== null), '409 response message is shown.');
    check.check(screen.queryByText('Synthetic Existing') !== null, '409 response includes the matched account display name.');
    check.check(createInvite.mock.calls.length === 1, '409 comes from the invite create request.');
    check.finish();
  });

  it('shows the no-admin-email response without hiding the form result', async () => {
    const check = collectFailures();
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }], questions: [] });
    getInvites.mockResolvedValue({ invites: [], pendingCount: 0 });
    createInvite.mockImplementation(async (year: number, input: { email?: string }) => {
      if (year !== 2026 || input.email !== 'reader@smith.example') throw new Error('unexpected no-admin-email request');
      throw { response: { status: 422, data: { error: 'Your account has no Smith email on file; sign in again.' } } };
    });
    renderPanel();
    const email = screen.queryByLabelText('Email address');
    const submit = screen.queryByRole('button', { name: 'Send invite' });
    if (email) await userEvent.type(email, 'reader@smith.example');
    if (submit) await userEvent.click(submit);
    check.check(await settle(() => screen.queryByText('Your account has no Smith email on file; sign in again.') !== null), 'No-admin-email response is shown to the admin.');
    check.check(screen.queryByLabelText('Email address') !== null, 'The form remains available after the validation response.');
    check.check(createInvite.mock.calls.length === 1, 'No-admin-email condition is returned by the server request.');
    check.finish();
  });

  it('lists invites and sends resend, revoke, and copy actions', async () => {
    const check = collectFailures();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    getAssignments.mockResolvedValue({ surveyYear: 2026, sections: [{ section: 'Circulation', assignees: [] }], questions: [] });
    getInvites.mockResolvedValue({ invites: [{ ...pendingInvite, inviteText }], pendingCount: 1 });
    resendInvite.mockImplementation(async (id: number) => {
      if (id !== 31) throw new Error('unexpected resend id');
      return { invite: { ...pendingInvite, sendCount: 2 }, emailSent: true, inviteText };
    });
    revokeInvite.mockImplementation(async (id: number) => {
      if (id !== 31) throw new Error('unexpected revoke id');
      return { invite: { ...pendingInvite, status: 'revoked' } };
    });
    renderPanel();
    check.check(await settle(() => screen.queryByText('This year\'s invites') !== null), 'This year\'s invite list is shown.');
    check.check(screen.queryByText('pending') !== null, 'Invite status is shown.');
    check.check(screen.queryByText('Q60 · A') !== null, 'Invite targets are shown with their question label.');
    check.check(screen.queryByText('2026-11-05 10:00:00') !== null, 'Last-sent time is shown.');
    const resend = screen.queryByRole('button', { name: 'Resend invite to reader@smith.example' });
    const revoke = screen.queryByRole('button', { name: 'Revoke invite to reader@smith.example' });
    const copy = screen.queryByRole('button', { name: 'Copy invite text for reader@smith.example' });
    if (resend) await userEvent.click(resend);
    if (revoke) await userEvent.click(revoke);
    if (copy) await userEvent.click(copy);
    check.check(await settle(() => resendInvite.mock.calls.length === 1), 'Resend action reaches the API.');
    check.check(resendInvite.mock.calls[0]?.[0] === 31, 'Resend API uses the invite id.');
    check.check(revokeInvite.mock.calls.length === 1 && revokeInvite.mock.calls[0]?.[0] === 31, 'Revoke action reaches the API with the invite id.');
    check.check(writeText.mock.calls[0]?.[0] === inviteText.subject + '\n\n' + inviteText.body, 'List copy action uses the invite text shown by the server.');
    const inviteCard = screen.getByRole('article');
    check.check(within(inviteCard).queryByText(inviteText.subject) !== null, 'List copy action displays the pending invite subject.');
    const copyBox = within(inviteCard).queryByText(inviteText.subject)?.parentElement;
    check.check(copyBox?.querySelector('pre')?.textContent === inviteText.body, 'List copy action displays the pending invite body.');
    check.finish();
  });
});
