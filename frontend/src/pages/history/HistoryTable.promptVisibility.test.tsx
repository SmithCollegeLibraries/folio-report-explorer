import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import HistoryTable from './HistoryTable';
import type { HistoryItem } from '../../types';

afterEach(() => {
  cleanup();
});

function makeHistoryItem(overrides: Partial<HistoryItem>): HistoryItem {
  return {
    jobId: overrides.jobId ?? 'job-1',
    name: overrides.name ?? null,
    status: overrides.status ?? 'completed',
    sql: overrides.sql ?? 'SELECT 1;',
    source: overrides.source ?? 'nl',
    dataSource: overrides.dataSource ?? 'folio',
    progressMessage: overrides.progressMessage ?? null,
    rowCount: overrides.rowCount ?? 0,
    executionTimeMs: overrides.executionTimeMs ?? 0,
    errorMessage: overrides.errorMessage ?? null,
    createdAt: overrides.createdAt ?? '2026-05-27T12:00:00Z',
    startedAt: overrides.startedAt ?? null,
    completedAt: overrides.completedAt ?? null,
    runBy: overrides.runBy ?? null,
    canDelete: overrides.canDelete ?? true,
  };
}

function renderHistoryTable(items: HistoryItem[], onOpen = vi.fn()) {
  return render(
    <HistoryTable
      items={items}
      filteredItems={items}
      loading={false}
      allSelectableChecked={false}
      hasSelectableItems
      onToggleSelectAll={vi.fn()}
      renamingId={null}
      renameValue=""
      onRenameValueChange={vi.fn()}
      renameSaving={false}
      cancellingId={null}
      deletingId={null}
      confirmDeleteId={null}
      expandedErrors={new Set()}
      expandedSql={new Set()}
      copiedSqlId={null}
      selectedIds={new Set()}
      onOpen={onOpen}
      onCancel={vi.fn()}
      onToggleError={vi.fn()}
      onToggleSql={vi.fn()}
      onCopySql={vi.fn()}
      onStartRename={vi.fn()}
      onCommitRename={vi.fn()}
      onCancelRename={vi.fn()}
      onConfirmDelete={vi.fn()}
      onCancelDelete={vi.fn()}
      onDelete={vi.fn()}
      onSave={vi.fn()}
      onToggleSelect={vi.fn()}
    />,
  );
}

describe('HistoryTable prompt visibility', () => {
  it('clamps long completed and active request names to one line', () => {
    const completedPrompt = 'List the records in the SC Special Collections Browsing collection, with their HRID, Call Number Prefix, Call Number, Author, and Title, along with whether or not there are multiple holdings records and whether the same OCLC number is on a different record.';
    const activePrompt = 'Running request for every SC Rare Book Collection Reference holding with full title, author, call number, related institution, and OCLC comparison details that should remain readable in the active row.';

    renderHistoryTable([
      makeHistoryItem({ jobId: 'completed-job', name: completedPrompt, status: 'completed', completedAt: '2026-05-27T13:00:00Z' }),
      makeHistoryItem({ jobId: 'active-job', name: activePrompt, status: 'running', startedAt: '2026-05-27T13:05:00Z' }),
    ]);

    const completedName = screen.getByText(completedPrompt);
    const activeName = screen.getByText(activePrompt);

    expect(completedName).toHaveClass('truncate');
    expect(activeName).toHaveClass('truncate');
    expect(completedName).toHaveAttribute('title', completedPrompt);
    expect(activeName).toHaveAttribute('title', activePrompt);
  });

  it('opens the completed row modal from View original query', async () => {
    const onOpen = vi.fn();
    const completedPrompt = 'List the records in the SC Special Collections Browsing collection with every detail requested by the user.';

    renderHistoryTable([
      makeHistoryItem({ jobId: 'completed-job', name: completedPrompt, status: 'completed', completedAt: '2026-05-27T13:00:00Z' }),
    ], onOpen);

    await userEvent.click(screen.getByRole('button', { name: /view original query/i }));

    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ jobId: 'completed-job' }));
  });
});
