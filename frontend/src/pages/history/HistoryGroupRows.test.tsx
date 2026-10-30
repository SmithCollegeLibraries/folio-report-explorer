import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import HistoryGroupRows from './HistoryGroupRows';
import type { HistoryGroupListItem } from '../../api/history';
import type { HistoryItem } from '../../types';

afterEach(() => cleanup());

function member(jobId: string, status: HistoryItem['status'] = 'completed'): HistoryItem {
  return {
    jobId,
    name: `Synthetic run ${jobId}`,
    status,
    sql: 'SELECT 1',
    source: 'report',
    dataSource: 'local',
    progressMessage: null,
    rowCount: 3,
    executionTimeMs: 10,
    errorMessage: null,
    createdAt: '2026-07-19T10:00:00Z',
    startedAt: null,
    completedAt: '2026-07-19T10:01:00Z',
    runBy: 'synthetic@example.test',
    canDelete: true,
  };
}

function groupItem(): HistoryGroupListItem {
  return {
    ...member('newest'),
    groupId: 'stable-group-id',
    runCount: 3,
    activeRunCount: 1,
  };
}

function renderGroupRows(overrides: Partial<React.ComponentProps<typeof HistoryGroupRows>> = {}) {
  const props: React.ComponentProps<typeof HistoryGroupRows> = {
    group: groupItem(),
    members: [member('older'), member('active', 'running')],
    expanded: false,
    loading: false,
    onToggle: vi.fn(),
    onOpen: vi.fn(),
    onDelete: vi.fn(),
    onCancel: vi.fn(),
    onRename: vi.fn(),
    ...overrides,
  };
  return { ...render(<HistoryGroupRows {...props} />), props };
}

describe('HistoryGroupRows', () => {
  it('shows the retained run count and expands by stable group identity', async () => {
    const { props } = renderGroupRows();

    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /run 3 times/i }));
    expect(props.onToggle).toHaveBeenCalledWith(expect.objectContaining({ groupId: 'stable-group-id' }));
  });

  it('shows member controls and sends open, delete, cancel, and rename to the selected run', async () => {
    const { props } = renderGroupRows({ expanded: true });
    const olderRow = screen.getByText('Synthetic run older').closest('tr');
    const activeRow = screen.getByText('Synthetic run active').closest('tr');
    expect(olderRow).not.toBeNull();
    expect(activeRow).not.toBeNull();

    const user = userEvent.setup();
    await user.click(within(olderRow!).getByRole('button', { name: /open/i }));
    await user.click(within(olderRow!).getByRole('button', { name: /rename/i }));
    await user.click(within(olderRow!).getByRole('button', { name: /delete/i }));
    await user.click(within(activeRow!).getByRole('button', { name: /cancel/i }));

    expect(props.onOpen).toHaveBeenCalledWith(expect.objectContaining({ jobId: 'older' }));
    expect(props.onRename).toHaveBeenCalledWith(expect.objectContaining({ jobId: 'older' }));
    expect(props.onDelete).toHaveBeenCalledWith('older');
    expect(props.onCancel).toHaveBeenCalledWith('active');
  });
});
