import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DashboardWidgetGallery from './DashboardWidgetGallery';

const { listReportsMock, fetchWidgetsMock } = vi.hoisted(() => ({
  listReportsMock: vi.fn(),
  fetchWidgetsMock: vi.fn(),
}));

vi.mock('../api/client', () => ({
  fetchDashboardWidgets: fetchWidgetsMock,
  listReports: listReportsMock,
  addDashboardWidget: vi.fn(),
  removeDashboardWidget: vi.fn(),
  createAdminWidget: vi.fn(),
  updateAdminWidget: vi.fn(),
  deleteAdminWidget: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('Dashboard widget gallery and MARC combination finder', () => {
  it('does not offer the governed combination report as a dashboard widget template', async () => {
    fetchWidgetsMock.mockResolvedValue([]);
    listReportsMock.mockResolvedValue({
      cataloging: [{
        id: 29,
        slug: 'marc-field-combination-finder',
        name: 'MARC Field Combination Finder',
        description: 'Finds instances that match combinations of MARC fields.',
        category: 'cataloging',
        parameterCount: 43,
        defaultLimit: 100000,
        createdBy: 'manual',
        createdAt: '2026-11-05T00:00:00Z',
      }],
    });

    const user = userEvent.setup();
    render(<DashboardWidgetGallery isAdmin onClose={() => {}} onChanged={() => {}} />);
    await user.click(await screen.findByRole('button', { name: 'New Widget Template' }));

    const reportPicker = await screen.findByLabelText('Report Template');
    expect(reportPicker.querySelector('option[value="29"]')).toBeNull();
  });
});
