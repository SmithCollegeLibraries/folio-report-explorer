import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { HistoryItem, JobStatusResponse } from '../../types';
import HistoryResultsModal from './HistoryResultsModal';

afterEach(cleanup);

const item: HistoryItem = {
  jobId: 'synthetic-combination-history-job',
  name: 'MARC Field Combination Finder — All conditions: 655 #7 $a and 563 $a',
  status: 'completed',
  sql: 'SELECT synthetic combination rows',
  source: 'report',
  dataSource: 'folio',
  progressMessage: 'Completed',
  rowCount: 100000,
  executionTimeMs: 15,
  errorMessage: null,
  createdAt: '2026-11-05T10:00:00Z',
  startedAt: '2026-11-05T10:00:01Z',
  completedAt: '2026-11-05T10:00:02Z',
  runBy: null,
  canDelete: true,
};

function renderModal(job: JobStatusResponse) {
  return render(
    <HistoryResultsModal
      item={item}
      job={job}
      loading={false}
      onClose={() => {}}
      onRename={() => {}}
      onSave={() => {}}
      suggestions={[]}
      suggestionSource={null}
      suggestionWarnings={[]}
      suggestionsLoading={false}
      suggestionsError={null}
      onGenerateSuggestions={() => {}}
      onRunSuggestion={() => {}}
      onAskFollowUp={() => {}}
    />,
  );
}

describe('MARC combination finder history result warnings', () => {
  it('shows the 100,000-row cap warning for a truncated history result', () => {
    renderModal({
      jobId: item.jobId,
      status: 'completed',
      sql: item.sql,
      outputMode: 'table',
      progressMessage: 'Completed',
      createdAt: item.createdAt,
      startedAt: item.startedAt,
      completedAt: item.completedAt,
      columns: ['Instance UUID', 'Title'],
      rows: [{ 'Instance UUID': 'synthetic-instance', Title: 'Synthetic title' }],
      rowCount: 100000,
      executionTimeMs: 15,
      truncated: true,
    });

    expect(screen.getByRole('alert')).toHaveTextContent('This report reached its 100,000-row cap.');
  });

  it('shows the skipped-identifier warning for UUID exports in history', () => {
    renderModal({
      jobId: item.jobId,
      status: 'completed',
      sql: item.sql,
      outputMode: 'file',
      downloadUrl: '/query/export/synthetic-combination-history-job',
      progressMessage: 'Completed',
      createdAt: item.createdAt,
      startedAt: item.startedAt,
      completedAt: item.completedAt,
      columns: ['Instance UUID'],
      rows: [{ 'Instance UUID': '00000000-0000-4000-8000-000000000001' }],
      rowCount: 99998,
      executionTimeMs: 15,
      truncated: true,
      identifierSkippedCount: 2,
    });

    const alerts = screen.getAllByRole('alert');
    expect(alerts[0]).toHaveTextContent('This report reached its 100,000-row cap.');
    expect(alerts[1]).toHaveTextContent('2 identifiers could not be exported because they were not a valid FOLIO UUID.');
  });
});
