import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import ReportParameterFields from './ReportParameterFields';
import type { ReportParam } from '../types';

afterEach(cleanup);

describe('shared record report parameters', () => {
  it('enables selecting all synthetic locations for a campus group', async () => {
    const user = userEvent.setup();
    const locations: ReportParam = {
      name: 'locationIds', type: 'multiselect', label: 'Locations', required: false,
      default: '', resolvedDefault: '', max_selections: 100,
    };

    render(
      <ReportParameterFields
        slug="shared-record-check"
        parameters={[locations]}
        values={{ locationIds: '' }}
        selectOptions={{
          locationIds: [
            { value: 'synthetic-location-a', label: 'Campus A — Library A — Location A [A1]' },
            { value: 'synthetic-location-b', label: 'Campus A — Library B — Location B [B1]' },
          ],
        }}
        serverFieldErrors={{}}
        onChange={() => {}}
      />,
    );

    await user.click(screen.getByRole('button', { name: 'Select locations' }));
    expect(screen.getByRole('button', { name: 'Select all Campus A (2)' })).toBeInTheDocument();
  });
});
