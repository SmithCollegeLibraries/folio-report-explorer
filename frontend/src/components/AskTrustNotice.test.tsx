import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AskTrustNotice from './AskTrustNotice';
import HistoryResultsModal from '../pages/history/HistoryResultsModal';
import type { HistoryItem, JobStatusResponse } from '../types';

afterEach(cleanup);

describe('AskTrustNotice', () => {
  it('shows verified provenance for a canonical report', () => {
    render(
      <AskTrustNotice
        generationProvenance="verified_pattern"
        provenanceLabel="Verified pattern"
      />,
    );

    expect(screen.getByRole('note')).toHaveTextContent('Verified pattern');
    expect(screen.queryByText('AI-built')).not.toBeInTheDocument();
  });

  it('shows AI-built provenance without making the result an error', () => {
    render(
      <AskTrustNotice
        generationProvenance="ai_built"
        provenanceLabel="AI-built"
      />,
    );

    const notice = screen.getByRole('note');
    expect(screen.getByRole('heading', { name: 'AI-built' })).toBeInTheDocument();
    expect(screen.queryByText('Verified pattern')).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByText('This report was generated with AI assistance.')).toBeInTheDocument();
    expect(notice).not.toHaveTextContent(/route|validator|schema|table|%/i);
  });

  it('renders no review or correction controls in the AI-built notice', () => {
    render(
      <AskTrustNotice
        generationProvenance="ai_built"
        provenanceLabel="AI-built"
      />,
    );

    expect(screen.getByRole('heading', { name: 'AI-built' })).toBeInTheDocument();
    expect(screen.getByText('This report was generated with AI assistance.')).toBeInTheDocument();
    expect(screen.queryByText(/flagged for routine review/i)).not.toBeInTheDocument();
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
