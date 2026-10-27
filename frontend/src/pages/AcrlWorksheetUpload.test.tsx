import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlPage from './Acrl';

const { listYears, getThreshold, parseWorksheetFile, uploadWorksheet } = vi.hoisted(() => ({
  listYears: vi.fn(),
  getThreshold: vi.fn(),
  parseWorksheetFile: vi.fn(),
  uploadWorksheet: vi.fn(),
}));

vi.mock('../api/acrl', () => ({
  listAcrlCatalogueYears: listYears,
  getAcrlReview: vi.fn(),
  saveAcrlLinks: vi.fn(),
  publishAcrlYear: vi.fn(),
  uploadAcrlWorksheet: uploadWorksheet,
  confirmAcrlImport: vi.fn(),
  getAcrlThreshold: getThreshold,
  saveAcrlThreshold: vi.fn(),
  previewAcrlImport: vi.fn(),
}));
vi.mock('../services/acrlWorksheetParser', () => ({ parseWorksheetFile, sha256ForFile: vi.fn() }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('ACRL worksheet calculation preview', () => {
  it('shows unresolved calculation IDs and waits for review before uploading', async () => {
    listYears.mockResolvedValue([]);
    getThreshold.mockResolvedValue({ thresholdPercent: 10 });
    parseWorksheetFile.mockResolvedValue({
      items: [{
        sheet: 'survey', question_id: '44', part: 'A', section: 'Library collections', prompt: 'Synthetic total',
        answer_type: 'number', required: 'yes', calculated_from: 'sum of 40, 99', survey_order: 1,
      }],
      unresolved: [],
      unresolvedCalculations: ['44A: 99'],
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const user = userEvent.setup();
    render(<QueryClientProvider client={client}><AcrlPage /></QueryClientProvider>);

    await user.upload(screen.getByLabelText('Worksheet PDF'), new File(['synthetic'], 'worksheet.pdf', { type: 'application/pdf' }));

    expect(await screen.findByRole('heading', { name: 'Calculations that need checking' })).toBeInTheDocument();
    expect(screen.getByText('44A: 99')).toBeInTheDocument();
    expect(uploadWorksheet).not.toHaveBeenCalled();
  });
});
