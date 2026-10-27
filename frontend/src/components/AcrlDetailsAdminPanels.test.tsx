import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlInstructionUploadPanel from './AcrlInstructionUploadPanel';
import AcrlNotesImportPanel from './AcrlNotesImportPanel';

const { getReview, saveInstructions, previewNotes, confirmNotes, parseInstructions, hashFile } = vi.hoisted(() => ({
  getReview: vi.fn(), saveInstructions: vi.fn(), previewNotes: vi.fn(), confirmNotes: vi.fn(),
  parseInstructions: vi.fn(), hashFile: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlReview: getReview,
  saveAcrlInstructions: saveInstructions,
  previewAcrlNotesImport: previewNotes,
  confirmAcrlNotesImport: confirmNotes,
}));
vi.mock('../services/acrlInstructionParser', () => ({ parseInstructionFile: parseInstructions }));
vi.mock('../services/acrlWorksheetParser', () => ({ sha256ForFile: hashFile }));

afterEach(() => { cleanup(); vi.clearAllMocks(); });

const years = [{ surveyYear: 2026, status: 'published' as const, sourceFileName: null, surveyPromptCount: 48,
  trendsPromptCount: 0, surveyItemCount: 48, trendsItemCount: 0, totalItemCount: 48, publishedAt: null }];
const review = { surveyYear: 2026, previousYear: null, status: 'published' as const, unresolvedCount: 0, canPublish: true,
  previousQuestions: [], items: [{ status: 'new' as const, matchKind: null, score: 0, previous: null, topical: false,
    decision: null, linkId: null, current: { sheet: 'survey' as const, question_id: '40', part: '', section: 'Library collections',
      prompt: 'Physical book titles', answer_type: 'number' as const, required: 'yes' as const, calculated_from: null, survey_order: 1 } }] };

function renderWithQuery(element: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{element}</QueryClientProvider>);
}

describe('ACRL details administrator panels', () => {
  it('parses an instructions PDF in the browser and previews coverage before saving linked blocks', async () => {
    getReview.mockResolvedValue(review);
    parseInstructions.mockResolvedValue({
      blocks: [{ blockKey: 'question:40', heading: 'Q40', text: 'Count titles.', kind: 'question' }],
      links: [{ sheet: 'survey', question_id: '40', part: '', blockKey: 'question:40' }],
      assignments: [], missingQuestionKeys: [], duplicateBlockKeys: [], coverage: { individual: 48, grouped: 25, missing: 0 },
    });
    hashFile.mockResolvedValue('a'.repeat(64));
    saveInstructions.mockResolvedValue({ success: true, surveyYear: 2026, blockCount: 1, linkCount: 1 });
    renderWithQuery(<AcrlInstructionUploadPanel years={years} />);
    const file = new File(['pdf bytes'], 'instructions.pdf', { type: 'application/pdf' });
    const input = await screen.findByLabelText('Instructions PDF');
    await waitFor(() => expect(input).toBeEnabled());
    await userEvent.upload(input, file);
    expect(await screen.findByText('Coverage: 48 individual · 25 grouped · 0 missing')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Save instructions' }));
    await waitFor(() => expect(saveInstructions).toHaveBeenCalledWith(2026, expect.objectContaining({
      fileName: 'instructions.pdf', sha256: 'a'.repeat(64),
      blocks: [expect.objectContaining({ blockKey: 'question:40', sourceFile: 'instructions.pdf' })],
    })));
    expect(await screen.findByText('Saved 1 instruction block and 1 question link.')).toBeInTheDocument();
  });

  it('shows duplicate instruction keys and prevents saving the preview', async () => {
    getReview.mockResolvedValue(review);
    parseInstructions.mockResolvedValue({
      blocks: [{ blockKey: 'question:40', heading: 'Q40', text: 'Count titles.', kind: 'question' }],
      links: [{ sheet: 'survey', question_id: '40', part: '', blockKey: 'question:40' }],
      assignments: [], missingQuestionKeys: [], duplicateBlockKeys: ['question:40'],
      coverage: { individual: 48, grouped: 25, missing: 0 },
    });
    renderWithQuery(<AcrlInstructionUploadPanel years={years} />);
    const input = await screen.findByLabelText('Instructions PDF');
    await waitFor(() => expect(input).toBeEnabled());
    await userEvent.upload(input, new File(['pdf bytes'], 'instructions.pdf', { type: 'application/pdf' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Duplicate instruction block keys: question:40');
    expect(screen.getByRole('button', { name: 'Save instructions' })).toBeDisabled();
    expect(saveInstructions).not.toHaveBeenCalled();
  });

  it('warns about missing instruction keys while allowing a linked preview to be saved', async () => {
    getReview.mockResolvedValue(review);
    parseInstructions.mockResolvedValue({
      blocks: [{ blockKey: 'question:40', heading: 'Q40', text: 'Count titles.', kind: 'question' }],
      links: [{ sheet: 'survey', question_id: '40', part: '', blockKey: 'question:40' }],
      assignments: [], missingQuestionKeys: ['75'], duplicateBlockKeys: [],
      coverage: { individual: 1, grouped: 0, missing: 1 },
    });
    hashFile.mockResolvedValue('a'.repeat(64));
    saveInstructions.mockResolvedValue({ success: true, surveyYear: 2026, blockCount: 1, linkCount: 1 });
    renderWithQuery(<AcrlInstructionUploadPanel years={years} />);
    const input = await screen.findByLabelText('Instructions PDF');
    await waitFor(() => expect(input).toBeEnabled());
    await userEvent.upload(input, new File(['pdf bytes'], 'instructions.pdf', { type: 'application/pdf' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Questions without individual or grouped instructions: 75');
    const saveButton = screen.getByRole('button', { name: 'Save instructions' });
    expect(saveButton).toBeEnabled();
    await userEvent.click(saveButton);
    await waitFor(() => expect(saveInstructions).toHaveBeenCalledWith(2026, expect.objectContaining({
      fileName: 'instructions.pdf', blocks: [expect.objectContaining({ blockKey: 'question:40' })],
    })));
  });

  it('previews all note CSV rows and imports only after confirmation', async () => {
    getReview.mockResolvedValue(review);
    previewNotes.mockResolvedValue({ surveyYear: 2026, matchedCount: 1, unknownCount: 0, invalidCount: 0,
      items: [{ line: 2, questionId: '40', part: '', originYear: 2024, source: 'FY2024 notes', status: 'will_import', error: null }] });
    confirmNotes.mockResolvedValue({ preview: {}, result: { imported: 1, unchanged: 0, skipped: 0 } });
    renderWithQuery(<AcrlNotesImportPanel years={years} />);
    const csv = 'question_id,part,note,source\n40,,Count searchable titles,FY2024 notes';
    const file = new File([csv], 'collection-notes.csv', { type: 'text/csv' });
    Object.defineProperty(file, 'text', { value: async () => csv });
    await userEvent.upload(await screen.findByLabelText('Collection notes CSV'), file);
    expect(await screen.findByText('1 note rows loaded · 1 question keys found in this year')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Preview notes import' }));
    expect(await screen.findByText('Matched 1 · unknown 0 · invalid 0')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Confirm notes import' }));
    await waitFor(() => expect(confirmNotes).toHaveBeenCalledWith(2026, [{
      question_id: '40', part: '', note: 'Count searchable titles', source: 'FY2024 notes',
    }]));
    expect(await screen.findByRole('status')).toHaveTextContent('Imported 1; unchanged 0; skipped 0.');
  });
});
