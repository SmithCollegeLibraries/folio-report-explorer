import { beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from './client';
import {
  acceptAcrlSuggestions,
  addAcrlQuestionNote,
  confirmAcrlImport,
  confirmAcrlNotesImport,
  copyAcrlAssignmentsFromPrevious,
  createAcrlInvite,
  deleteAcrlQuestionNote,
  editAcrlQuestionNote,
  getAcrlEntry,
  getAcrlInvites,
  getAcrlQuestionDetails,
  getAcrlSectionAssignments,
  previewAcrlImport,
  previewAcrlNotesImport,
  publishAcrlYear,
  resendAcrlInvite,
  revokeAcrlInvite,
  runAcrlAutofill,
  saveAcrlAnswer,
  saveAcrlQuestionAssignments,
  saveAcrlSectionAssignments,
  saveAcrlThreshold,
  setAcrlSectionStatus,
} from './acrl';

vi.mock('./client', () => ({
  api: {
    get: vi.fn().mockResolvedValue({ data: {} }),
    post: vi.fn().mockResolvedValue({ data: {} }),
    put: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn().mockResolvedValue({ data: {} }),
  },
}));

describe('ACRL API institution query', () => {
  beforeEach(() => vi.clearAllMocks());

  it('adds SC to every institution-scoped request', async () => {
    await Promise.all([
      getAcrlEntry(2026),
      getAcrlSectionAssignments(2026),
      saveAcrlSectionAssignments(2026, 'Access Services', [1]),
      saveAcrlQuestionAssignments(2026, { sheet: 'survey', questionId: 'Q1', part: '', userIds: [1] }),
      copyAcrlAssignmentsFromPrevious(2026),
      getAcrlInvites(2026),
      createAcrlInvite(2026, { email: 'person@smith.example', targets: [] }),
      saveAcrlAnswer(2026, { sheet: 'survey', question_id: 'Q1', part: '', value: '1', note: '', expected_updated_at: null }),
      runAcrlAutofill(2026, 'survey', 'Q1', ''),
      acceptAcrlSuggestions(2026, 'Access Services', [{ sheet: 'survey', question_id: 'Q1', part: '' }]),
      setAcrlSectionStatus(2026, 'Access Services', 'ready'),
      previewAcrlImport(2026, { rows: [], sourceLabel: 'synthetic', overwrite: false }),
      confirmAcrlImport(2026, { rows: [], sourceLabel: 'synthetic', overwrite: false }),
      getAcrlQuestionDetails(2026, 'survey', 'Q1', ''),
      addAcrlQuestionNote(2026, { sheet: 'survey', question_id: 'Q1', part: '', note: 'Synthetic', source: 'Synthetic', origin_year: 2025 }),
      previewAcrlNotesImport(2026, []),
      confirmAcrlNotesImport(2026, []),
    ]);

    const requestConfigs = [
      ...vi.mocked(api.get).mock.calls.map((call) => call[1]),
      ...vi.mocked(api.post).mock.calls.map((call) => call[2]),
    ];
    expect(requestConfigs).toHaveLength(17);
    expect(requestConfigs.every((config) => config?.params?.institution === 'SC')).toBe(true);
  });

  it('keeps shared and ID-only requests institution-free', async () => {
    await Promise.all([
      publishAcrlYear(2026),
      saveAcrlThreshold(10),
      resendAcrlInvite(4),
      revokeAcrlInvite(4),
      editAcrlQuestionNote(4, { note: 'Synthetic', source: 'Synthetic', origin_year: 2025 }),
      deleteAcrlQuestionNote(4),
    ]);

    const configs = [
      ...vi.mocked(api.post).mock.calls.map((call) => call[2]),
      ...vi.mocked(api.put).mock.calls.map((call) => call[2]),
      ...vi.mocked(api.delete).mock.calls.map((call) => call[1]),
    ];
    expect(configs.every((config) => !config?.params?.institution)).toBe(true);
  });

  it('preserves question detail fields and Vea weekStart with the institution', async () => {
    await getAcrlQuestionDetails(2026, 'survey', 'Q2', 'A');
    await runAcrlAutofill(2026, 'survey', 'Q2', '', '2025-02-09');

    expect(api.get).toHaveBeenCalledWith('/acrl/years/2026/question-details', {
      params: { sheet: 'survey', question_id: 'Q2', part: 'A', institution: 'SC' },
    });
    expect(api.post).toHaveBeenCalledWith('/acrl/years/2026/autofill/survey/Q2/_', { weekStart: '2025-02-09' }, {
      params: { institution: 'SC' },
    });
  });
});
