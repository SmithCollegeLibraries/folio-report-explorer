import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AskTrustNotice from './AskTrustNotice';
import HistoryResultsModal from '../pages/history/HistoryResultsModal';
import type { HistoryItem, JobStatusResponse } from '../types';

const assumptions = [{
  key: 'campus_scope',
  label: 'Campus scope',
  value: 'Smith College',
  explanation: 'Results are limited to Smith College.',
  correctionExample: 'Use all campuses.',
  source: 'explicit' as const,
}];

afterEach(cleanup);

describe('AskTrustNotice', () => {
  it('renders no notice for a canonical report', () => {
    render(
      <AskTrustNotice
        mode="canonical"
        reviewRequired={false}
        reviewNotice={{ title: 'Untrusted title', message: 'Untrusted message' }}
        assumptions={assumptions}
      />,
    );

    expect(screen.queryByRole('note')).not.toBeInTheDocument();
  });

  it('explains an ordinary AI-assisted report and its assumptions', () => {
    render(
      <AskTrustNotice
        mode="exploratory"
        reviewRequired={false}
        reviewNotice={{
          title: 'Internal classifier result',
          message: 'This report uses the assumptions shown here.',
        }}
        assumptions={assumptions}
      />,
    );

    const notice = screen.getByRole('note');
    expect(screen.getByRole('heading', { name: 'AI-assisted report' })).toBeInTheDocument();
    expect(screen.getByText('This report uses the assumptions shown here.')).toBeInTheDocument();
    expect(screen.getByText(/^Campus scope:/)).toBeInTheDocument();
    expect(screen.getByText('Smith College')).toBeInTheDocument();
    expect(notice).not.toHaveTextContent('Internal classifier result');
    expect(notice).not.toHaveTextContent(/route|validator|schema|table|%/i);
  });

  it('uses stronger nonblocking copy when routine review is flagged', () => {
    render(
      <AskTrustNotice
        mode="exploratory"
        reviewRequired
        reviewNotice={{
          title: 'Do not expose this title',
          message: 'The available reporting data has an important limitation for this question.',
        }}
        assumptions={[]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'AI-assisted report — review flagged' })).toBeInTheDocument();
    expect(screen.getByText(/important limitation/i)).toBeInTheDocument();
    expect(screen.getByText(/flagged for routine review/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /approve|continue|confirm/i })).not.toBeInTheDocument();
    expect(screen.getByRole('note')).not.toHaveTextContent(/review (the )?sql/i);
  });
});

describe('HistoryResultsModal review advisory', () => {
  const item: HistoryItem = {
    jobId: 'job-123',
    name: 'Collection report',
    status: 'completed',
    sql: 'SELECT title FROM inventory.instance__t',
    source: 'nl',
    dataSource: 'folio',
    progressMessage: 'Completed',
    rowCount: 1,
    executionTimeMs: 42,
    errorMessage: null,
    createdAt: '2026-07-21T12:00:00Z',
    startedAt: '2026-07-21T12:00:01Z',
    completedAt: '2026-07-21T12:00:02Z',
    runBy: null,
    canDelete: true,
    reviewAdvisory: {
      state: 'superseded',
      message: 'A corrected version of this report is available.',
      supersededByJobId: 'job-456',
    },
  };
  const job: JobStatusResponse = {
    jobId: item.jobId,
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
    executionTimeMs: 42,
  };

  it('shows only the safe advisory and keeps existing actions available', () => {
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

    expect(screen.getByRole('note')).toHaveTextContent('A corrected version of this report is available.');
    expect(screen.getByRole('button', { name: /ask follow-up/i })).toBeEnabled();
    expect(screen.getByRole('button', { name: /^save$/i })).toBeEnabled();
    expect(screen.getByRole('button', { name: /download csv/i })).toBeEnabled();
    expect(screen.queryByRole('button', { name: /approve|continue|confirm/i })).not.toBeInTheDocument();
  });
});
