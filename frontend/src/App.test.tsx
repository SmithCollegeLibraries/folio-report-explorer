import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App';

const { auth } = vi.hoisted(() => ({ auth: { current: null as any } }));
vi.mock('./hooks/useAuth', () => ({
  useAuth: () => auth.current,
  getShibbolethLoginUrl: () => '/login',
}));

afterEach(cleanup);

function renderApp() {
  return render(<MemoryRouter initialEntries={['/auth/pending']}><App /></MemoryRouter>);
}

describe('Local Data navigation', () => {
  it('shows the standalone link only to allocation editors among regular users', () => {
    auth.current = {
      user: { role: 'user', displayName: 'Editor' },
      isAdmin: false,
      authEnabled: true,
      canEditAllocationsFor: () => true,
      logout: vi.fn(),
    };
    const { rerender } = renderApp();
    expect(screen.getByRole('link', { name: /Local Data/ })).toHaveAttribute('href', '/local-data');

    auth.current = {
      user: { role: 'user', displayName: 'Reader' },
      isAdmin: false,
      authEnabled: true,
      canEditAllocationsFor: () => false,
      logout: vi.fn(),
    };
    rerender(<MemoryRouter initialEntries={['/auth/pending']}><App /></MemoryRouter>);
    expect(screen.queryByRole('link', { name: 'Local Data' })).not.toBeInTheDocument();
  });

  it('keeps Local Data in the admin navigation', async () => {
    auth.current = {
      user: { role: 'admin', displayName: 'Admin' },
      isAdmin: true,
      authEnabled: true,
      canEditAllocationsFor: () => true,
      logout: vi.fn(),
    };
    const user = userEvent.setup();
    renderApp();
    await user.click(screen.getByRole('button', { name: 'Admin' }));

    expect(screen.getByRole('link', { name: /Local Data/ })).toHaveAttribute('href', '/local-data');
    expect(screen.getByText('Manage expense allocations')).toBeInTheDocument();
  });
});

describe('ACRL administration navigation', () => {
  it('shows ACRL in the Admin menu for admins and hides it from regular users', async () => {
    auth.current = {
      user: { role: 'admin', displayName: 'Admin' },
      isAdmin: true,
      authEnabled: true,
      canEditAllocationsFor: () => true,
      logout: vi.fn(),
    };
    const user = userEvent.setup();
    const adminView = renderApp();
    await user.click(screen.getByRole('button', { name: 'Admin' }));

    expect(screen.getByRole('link', { name: /ACRL.*Survey questions and yearly review/ })).toHaveAttribute('href', '/acrl');
    await user.click(screen.getByRole('button', { name: 'Admin' }));
    await user.click(screen.getByRole('button', { name: 'Open menu' }));
    const adminButtons = screen.getAllByRole('button', { name: 'Admin' });
    const mobileAdminButton = adminButtons[adminButtons.length - 1];
    await user.click(mobileAdminButton);
    expect(screen.getByRole('link', { name: 'ACRL' })).toHaveAttribute('href', '/acrl');

    adminView.unmount();
    auth.current = {
      user: { role: 'user', displayName: 'Reader' },
      isAdmin: false,
      authEnabled: true,
      canEditAllocationsFor: () => false,
      logout: vi.fn(),
    };
    renderApp();

    expect(screen.queryByRole('button', { name: 'Admin' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /ACRL/ })).not.toBeInTheDocument();
  });
});
