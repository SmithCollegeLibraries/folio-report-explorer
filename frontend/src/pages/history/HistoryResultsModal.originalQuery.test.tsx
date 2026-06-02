import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import HistoryResultsModal from './HistoryResultsModal';
import type { HistoryItem, JobStatusResponse } from '../../types';

const originalQuery = 'Please provide a list of titles with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges.';

const item: HistoryItem = {
  jobId: 'job-123',
  name: originalQuery,
  status: 'completed',
  sql: 'SELECT inst.title FROM inventory.instance__t inst',
  source: 'nl',
  dataSource: 'folio',
  progressMessage: 'Completed',
  rowCount: 1,
  executionTimeMs: 123,
  errorMessage: null,
  createdAt: '2026-05-29T10:00:00Z',
  startedAt: '2026-05-29T10:00:01Z',
  completedAt: '2026-05-29T10:00:02Z',
  runBy: null,
  canDelete: true,
};

const job: JobStatusResponse = {
  jobId: 'job-123',
  status: 'completed',
  sql: item.sql,
  dataSource: 'folio',
  progressMessage: 'Completed',
  createdAt: item.createdAt,
  startedAt: item.startedAt,
  completedAt: item.completedAt,
  columns: ['title'],
  rows: [{ title: 'Example' }],
  rowCount: 1,
  executionTimeMs: 123,
};

describe('HistoryResultsModal original query', () => {
  it('shows the full original query with a copy action', () => {
    render(
      <HistoryResultsModal
        item={item}
        job={job}
        loading={false}
        onClose={vi.fn()}
        onRename={vi.fn()}
        onSave={vi.fn()}
        suggestions={[]}
        suggestionSource={null}
        suggestionWarnings={[]}
        suggestionsLoading={false}
        suggestionsError={null}
        onGenerateSuggestions={vi.fn()}
        onRunSuggestion={vi.fn()}
        onAskFollowUp={vi.fn()}
      />,
    );

    expect(screen.getByText('Original query')).toBeInTheDocument();
    expect(screen.getAllByText(originalQuery).length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: /copy original query/i })).toBeInTheDocument();
  });
});
