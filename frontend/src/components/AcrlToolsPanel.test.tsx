import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlToolsPanel from './AcrlToolsPanel';
import type { AcrlSurveyYear } from '../types/acrl';

const { getThreshold, saveThreshold, previewImport, confirmImport } = vi.hoisted(() => ({
  getThreshold: vi.fn(),
  saveThreshold: vi.fn(),
  previewImport: vi.fn(),
  confirmImport: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  getAcrlThreshold: getThreshold,
  saveAcrlThreshold: saveThreshold,
  previewAcrlImport: previewImport,
  confirmAcrlImport: confirmImport,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const years: AcrlSurveyYear[] = [{
  surveyYear: 2025, status: 'published', sourceFileName: null, surveyPromptCount: 69, trendsPromptCount: 15,
  surveyItemCount: 79, trendsItemCount: 15, totalItemCount: 94, publishedAt: '2026-01-01',
}];

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AcrlToolsPanel years={years} /></QueryClientProvider>);
}

describe('ACRL administrator tools', () => {
  it('previews CSV rows and confirms a clean import', async () => {
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    previewImport.mockResolvedValue({
      surveyYear: 2025, sourceLabel: 'FY2025 source', overwrite: true,
      matchedCount: 1, unknownCount: 0, typeErrorCount: 0, existingChanges: 1,
      items: [{ line: 2, sheet: 'survey', questionId: '60', part: 'A', status: 'will_replace', currentValue: '100', value: '18527', error: null }],
    });
    confirmImport.mockResolvedValue({ preview: { matchedCount: 1 }, result: { imported: 1, unchanged: 0, skipped: 0 } });
    renderPanel();
    const file = new File(['sheet,question_id,part,value,note\nsurvey,60,A,18527,'], 'values.csv', { type: 'text/csv' });
    Object.defineProperty(file, 'text', { value: async () => 'sheet,question_id,part,value,note\nsurvey,60,A,18527,' });
    await userEvent.upload(screen.getByLabelText('CSV file'), file);
    await screen.findByText('1 CSV answer rows loaded.');
    await userEvent.type(screen.getByLabelText('Source label'), 'FY2025 source');
    await userEvent.click(screen.getByLabelText('Overwrite existing non-empty values'));
    await userEvent.click(screen.getByRole('button', { name: 'Preview import' }));
    expect(await screen.findByText(/Matched 1 · unknown IDs 0 · type errors 0/)).toBeInTheDocument();
    expect(screen.getByText(/current 100 → proposed 18527/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Confirm import' }));
    await waitFor(() => expect(confirmImport).toHaveBeenCalledWith(2025, expect.objectContaining({ sourceLabel: 'FY2025 source', overwrite: true })));
    expect(await screen.findByRole('status')).toHaveTextContent('Imported 1; unchanged 0; skipped 0.');
  });
});
