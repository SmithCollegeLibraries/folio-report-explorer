import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import AuthCallback from './AuthCallback';

const { login } = vi.hoisted(() => ({ login: vi.fn() }));

vi.mock('../hooks/useAuth', () => ({
  useAuth: () => ({ login }),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('AuthCallback', () => {
  it('renders the return-home link under the router basename', async () => {
    render(
      <MemoryRouter basename="/fre" initialEntries={["/fre/auth/callback"]} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <AuthCallback />
      </MemoryRouter>,
    );

    const link = await screen.findByRole('link', { name: 'Return to Home' });
    expect(link.getAttribute('href')).toMatch(/^\/fre\//);
    expect(link).toHaveAttribute('href', '/fre/');
  });
});
