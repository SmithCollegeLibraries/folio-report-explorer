import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import VeaConnectionCard from './VeaConnectionCard';

const veaApi = vi.hoisted(() => ({
  fetchVeaStatus: vi.fn(),
  testVeaConnection: vi.fn(),
  fetchVeaZones: vi.fn(),
  saveVeaGateZones: vi.fn(),
}));

vi.mock('../api/vea', () => veaApi);

function renderCard() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <VeaConnectionCard />
    </QueryClientProvider>,
  );
}

describe('Vea connection card', () => {
  beforeEach(() => vi.clearAllMocks());
  afterEach(() => cleanup());

  it('hides controls when unconfigured, tests on demand, and keeps persisted doors after a failed save', async () => {
    veaApi.fetchVeaStatus.mockResolvedValueOnce({ configured: false, selectedZoneIds: [] });
    const { unmount } = renderCard();
    expect(await screen.findByText('Add VEA_CLIENT_ID and VEA_CLIENT_SECRET to the server .env')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Test connection' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Load doors' })).not.toBeInTheDocument();
    unmount();

    veaApi.fetchVeaStatus.mockResolvedValue({ configured: true, selectedZoneIds: ['door-a'] });
    veaApi.testVeaConnection
      .mockResolvedValueOnce({ ok: true, zoneCount: 3 })
      .mockRejectedValueOnce({ response: { data: { error: 'auth_failed' } } });
    veaApi.fetchVeaZones.mockResolvedValue([
      { zoneId: 'door-a', name: 'Main Door', countType: 1, countApplication: 1, disabled: false, selectable: true },
      { zoneId: 'door-b', name: 'South Door', countType: 1, countApplication: 1, disabled: false, selectable: true },
      { zoneId: 'queue', name: 'Queue', countType: 5, countApplication: 5, disabled: false, selectable: false },
      { zoneId: 'closed', name: 'Closed Door', countType: 1, countApplication: 1, disabled: true, selectable: false },
    ]);
    veaApi.saveVeaGateZones
      .mockResolvedValueOnce({ selectedZoneIds: ['door-a'] })
      .mockRejectedValueOnce({ response: { data: { error: 'save_failed' } } });
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: 'Test connection' }));
    expect(await screen.findByText('Connected to Vea. 3 zones available.')).toBeInTheDocument();
    expect(veaApi.fetchVeaZones).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Load doors' }));
    const mainDoor = await screen.findByRole('checkbox', { name: 'Main Door' });
    const southDoor = screen.getByRole('checkbox', { name: 'South Door' });
    expect(veaApi.fetchVeaZones).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('checkbox', { name: /Queue.*not a traffic counter/ })).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: /Closed Door.*disabled/ })).toBeDisabled();

    fireEvent.click(southDoor);
    fireEvent.click(screen.getByRole('button', { name: 'Save doors' }));
    await waitFor(() => expect(veaApi.saveVeaGateZones.mock.calls[0][0]).toEqual(['door-a', 'door-b']));
    expect(await screen.findByText('Doors saved.')).toBeInTheDocument();
    expect(mainDoor).toBeChecked();
    expect(southDoor).not.toBeChecked();

    fireEvent.click(southDoor);
    fireEvent.click(screen.getByRole('button', { name: 'Test connection' }));
    expect(await screen.findByText('Vea rejected the credentials.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save doors' }));
    expect(await screen.findByText('Could not save the selected doors.')).toBeInTheDocument();
    expect(screen.queryByText('Doors saved.')).not.toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Main Door' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'South Door' })).not.toBeChecked();
  });
});
