import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import HistoryResultsModal from './HistoryResultsModal';
import type { HistoryItem, JobStatusResponse } from '../../types';

const item: HistoryItem = {
  jobId: 'job-123',
  name: 'Original MRBC title list',
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

describe('HistoryResultsModal follow-up action', () => {
  it('calls onAskFollowUp with the completed job id', async () => {
    const onAskFollowUp = vi.fn();

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
        onAskFollowUp={onAskFollowUp}
      />,
    );

    await userEvent.click(screen.getByRole('button', { name: /ask follow-up/i }));

    expect(onAskFollowUp).toHaveBeenCalledWith('job-123');
  });
});
