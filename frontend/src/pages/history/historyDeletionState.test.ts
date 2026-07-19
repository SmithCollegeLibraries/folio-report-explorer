import { describe, expect, it } from 'vitest';
import type { HistoryItem } from '../../types';
import { deriveHistoryDeletionState } from './historyDeletionState';

function historyItem(jobId: string): HistoryItem {
  return {
    jobId,
    name: null,
    status: 'completed',
    sql: 'SELECT 1;',
    source: 'nl',
    dataSource: 'folio',
    progressMessage: null,
    rowCount: 0,
    executionTimeMs: 0,
    errorMessage: null,
    createdAt: '2026-07-19T12:00:00Z',
    startedAt: null,
    completedAt: '2026-07-19T12:00:01Z',
    runBy: null,
    canDelete: true,
  };
}

describe('deriveHistoryDeletionState', () => {
  it('removes only successfully deleted items', () => {
    const kept = historyItem('kept');

    const state = deriveHistoryDeletionState(
      [historyItem('deleted'), kept],
      2,
      0,
      50,
      ['deleted'],
      null,
    );

    expect(state.items).toEqual([kept]);
    expect(state.total).toBe(1);
  });

  it('clamps the total at zero', () => {
    const state = deriveHistoryDeletionState([], 1, 0, 50, ['first', 'second'], null);

    expect(state.total).toBe(0);
  });

  it('keeps the current offset when some displayed items remain', () => {
    const state = deriveHistoryDeletionState(
      [historyItem('deleted'), historyItem('kept')],
      75,
      50,
      50,
      ['deleted'],
      null,
    );

    expect(state.offset).toBe(50);
  });

  it('moves to the previous page when every displayed item is deleted', () => {
    const state = deriveHistoryDeletionState(
      [historyItem('first'), historyItem('second')],
      52,
      50,
      50,
      ['first', 'second'],
      null,
    );

    expect(state.offset).toBe(0);
  });

  it('closes the modal only when its item was successfully deleted', () => {
    const deletedModal = deriveHistoryDeletionState(
      [historyItem('deleted'), historyItem('kept')],
      2,
      0,
      50,
      ['deleted'],
      'deleted',
    );
    const keptModal = deriveHistoryDeletionState(
      [historyItem('deleted'), historyItem('kept')],
      2,
      0,
      50,
      ['deleted'],
      'kept',
    );

    expect(deletedModal.closeModal).toBe(true);
    expect(keptModal.closeModal).toBe(false);
  });
});
