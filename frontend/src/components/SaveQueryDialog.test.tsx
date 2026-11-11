import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { saveQuery } from '../api/client';
import type { HistoryItem } from '../types';
import SaveQueryDialog from './SaveQueryDialog';

vi.mock('../api/client', () => ({ saveQuery: vi.fn() }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function historyItem(source: string, reportRun?: unknown): HistoryItem {
  return {
    jobId: 'report-job-12',
    name: 'Synthetic report run',
    status: 'completed',
    sql: 'SELECT :reportParameter',
    source,
    dataSource: 'folio',
    progressMessage: 'Completed',
    rowCount: 1,
    executionTimeMs: 10,
    errorMessage: null,
    createdAt: '2026-11-01T10:00:00Z',
    startedAt: '2026-11-01T10:00:01Z',
    completedAt: '2026-11-01T10:00:02Z',
    runBy: null,
    canDelete: true,
    ...(reportRun === undefined ? {} : { reportRun }),
  } as HistoryItem;
}

function openSaveDialog(item: HistoryItem, initialPinned = false) {
  return render(
    <SaveQueryDialog item={item} initialPinned={initialPinned} onClose={vi.fn()} onSaved={vi.fn()} />,
  );
}

describe('SaveQueryDialog report saves', () => {
  it('sends the report job ID without SQL and preserves Console, builder, and NL payloads', async () => {
    const payloads = [
      {
        source: 'report',
        reportRun: { reportTemplateId: 9, canSave: true, reason: null },
        initialPinned: true,
        want: { fromJobId: 'report-job-12', name: 'Synthetic report run', description: undefined, isPinned: true },
      },
      {
        source: 'manual',
        want: {
          name: 'Synthetic report run', description: undefined, generatedSql: 'SELECT :reportParameter',
          queryDefinition: { sql: 'SELECT :reportParameter' }, source: 'builder', isPinned: false,
        },
      },
      {
        source: 'builder',
        want: {
          name: 'Synthetic report run', description: undefined, generatedSql: 'SELECT :reportParameter',
          queryDefinition: { sql: 'SELECT :reportParameter' }, source: 'builder', isPinned: false,
        },
      },
      {
        source: 'nl',
        want: {
          name: 'Synthetic report run', description: undefined, generatedSql: 'SELECT :reportParameter',
          queryDefinition: { sql: 'SELECT :reportParameter' }, source: 'nl', isPinned: false,
        },
      },
    ];

    for (const { source, reportRun, initialPinned = false, want } of payloads) {
      openSaveDialog(historyItem(source, reportRun), initialPinned);
      fireEvent.click(screen.getByRole('button', { name: 'Save' }));
      await waitFor(() => expect(saveQuery).toHaveBeenLastCalledWith(want));
      cleanup();
    }
  });

  it.each([
    ['missing metadata', undefined, "This run's saved settings can't be read - run the report again to save it."],
    ['malformed metadata', { reportTemplateId: '9', canSave: true, reason: null }, "This run's saved settings can't be read - run the report again to save it."],
    ['an older run', { reportTemplateId: 9, canSave: false, reason: 'older_run' }, "Run this report again to save it - older runs didn't keep their settings."],
    ['a deleted report', { reportTemplateId: 9, canSave: false, reason: 'report_deleted' }, 'This report no longer exists.'],
  ])('does not submit a report run with %s', (_case, reportRun, reason) => {
    openSaveDialog(historyItem('report', reportRun));

    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    expect(screen.getByText(reason)).toBeInTheDocument();
    fireEvent.submit(screen.getByRole('button', { name: 'Save' }).closest('form')!);

    expect(saveQuery).not.toHaveBeenCalled();
  });
});
