import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import HistoryTable from './HistoryTable';
import type { HistoryGroupListItem, HistoryGroupResponse } from '../../api/history';
import type { HistoryItem } from '../../types';

afterEach(() => cleanup());

function group(overrides: Partial<HistoryGroupListItem> = {}): HistoryGroupListItem {
  return {
    jobId: 'representative',
    groupId: 'stable-group',
    runCount: 2,
    activeRunCount: 0,
    name: 'Synthetic report',
    status: 'completed',
    sql: 'SELECT 1',
    source: 'report',
    dataSource: 'folio',
    progressMessage: null,
    rowCount: 1,
    executionTimeMs: 1,
    errorMessage: null,
    createdAt: '2026-07-19T10:00:00Z',
    startedAt: null,
    completedAt: '2026-07-19T10:01:00Z',
    runBy: null,
    canDelete: true,
    ...overrides,
  };
}

function renderHistoryTable(
  items: HistoryGroupListItem[],
  expandedGroupIds = new Set<string>(),
  membersByGroup: Record<string, HistoryGroupResponse> = {},
) {
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
      onOpen={vi.fn()}
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
      expandedGroupIds={expandedGroupIds}
      membersByGroup={membersByGroup}
      onToggleGroup={vi.fn()}
    />,
  );
}

describe('HistoryTable grouped rendering', () => {
  it('closes the visible columns menu after an outside click', async () => {
    const user = userEvent.setup();
    renderHistoryTable([group()]);

    await user.click(screen.getByRole('button', { name: 'Columns' }));
    expect(screen.getByRole('checkbox', { name: 'Source' })).toBeInTheDocument();
    await user.click(document.body);

    expect(screen.queryByRole('checkbox', { name: 'Source' })).not.toBeInTheDocument();
  });

  it('keeps the server-provided active-first order until a column is sorted', () => {
    const olderActive = group({
      jobId: 'older-active',
      groupId: 'active-group',
      name: 'Older active group',
      status: 'running',
      activeRunCount: 1,
      startedAt: '2026-07-01T10:00:00Z',
      completedAt: null,
    });
    const newerCompleted = group({
      jobId: 'newer-completed',
      groupId: 'completed-group',
      name: 'Newer completed group',
      runCount: 1,
      completedAt: '2026-07-20T10:00:00Z',
    });
    renderHistoryTable([olderActive, newerCompleted]);

    const rows = Array.from(screen.getByRole('table').querySelectorAll('tbody > tr'));
    const activeIndex = rows.findIndex((row) => row.textContent?.includes('Older active group'));
    const completedIndex = rows.findIndex((row) => row.textContent?.includes('Newer completed group'));

    expect(activeIndex).toBeGreaterThanOrEqual(0);
    expect(activeIndex).toBeLessThan(completedIndex);
  });

  it('spans expanded member content across the visible table columns', () => {
    const representative = group();
    const member: HistoryItem = { ...representative, jobId: 'older-run', name: 'Older synthetic run' };
    const page: HistoryGroupResponse = { items: [representative, member], total: 2, offset: 0, limit: 20 };
    renderHistoryTable([representative], new Set(['stable-group']), { 'stable-group': page });

    const table = screen.getByRole('table');
    const memberCell = screen.getByText('Older synthetic run').closest('tr')?.querySelector('td');
    const visibleColumnCount = table.querySelectorAll('thead th').length;

    expect(memberCell).not.toBeNull();
    expect(memberCell).toHaveAttribute('colspan', String(visibleColumnCount));
  });
});
