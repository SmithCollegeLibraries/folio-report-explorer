import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { checkJobStatus } from '../api/client';
import type { DashboardItem, JobStatusResponse } from '../types';
import { DashboardCard } from './Dashboard';

vi.mock('../api/client', () => ({
  cancelJob: vi.fn(),
  checkJobStatus: vi.fn(),
  fetchDashboard: vi.fn(),
  fetchDashboardWidgets: vi.fn(),
  hideDashboardItem: vi.fn(),
  refreshDashboardCard: vi.fn(),
  removeDashboardWidget: vi.fn(),
  reorderDashboard: vi.fn(),
  saveDashboardDisplay: vi.fn(),
  showDashboardItem: vi.fn(),
  toggleGlobal: vi.fn(),
  togglePin: vi.fn(),
}));

const dashboardItem: DashboardItem = {
  id: 4,
  name: 'Cached export',
  description: null,
  source: 'builder',
  is_pinned: false,
  is_global: false,
  last_job_id: 'job-cache-42',
  created_at: '2026-10-23T12:00:00Z',
  updated_at: '2026-10-23T12:00:00Z',
  source_type: 'personal',
  position: 0,
  display_type: 'table',
  chart_config: null,
};

const cachedFileStatus: JobStatusResponse = {
  jobId: 'job-cache-42',
  status: 'completed',
  outputMode: 'file',
  downloadUrl: '/query/export/job-cache-42',
  sql: 'SELECT instance_uuid FROM instance',
  progressMessage: 'Completed',
  createdAt: null,
  startedAt: null,
  completedAt: null,
  rowCount: 241,
  executionTimeMs: 24,
};

describe('Dashboard cached file exports', () => {
  beforeEach(() => vi.mocked(checkJobStatus).mockResolvedValue(cachedFileStatus));
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('keeps a no-preview export available to the expanded results modal', async () => {
    const onExpand = vi.fn();
    render(
      <DashboardCard
        item={dashboardItem}
        isAdmin={false}
        isDragging={false}
        isDragOver={false}
        onDragStart={() => undefined}
        onDragOver={() => undefined}
        onDrop={() => undefined}
        onDragEnd={() => undefined}
        onUnpin={() => undefined}
        onHide={() => undefined}
        onToggleGlobal={() => undefined}
        onExpand={onExpand}
      />,
    );

    fireEvent.click(await screen.findByTitle('Expand results'));

    expect(onExpand).toHaveBeenCalledWith(
      expect.objectContaining({
        jobId: 'job-cache-42',
        outputMode: 'file',
        downloadUrl: '/query/export/job-cache-42',
        columns: [],
        rows: [],
      }),
      'Cached export',
    );
  });
});
