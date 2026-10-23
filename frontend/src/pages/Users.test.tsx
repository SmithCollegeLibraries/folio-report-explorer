import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Users from './Users';
import type { AuthUser } from '../types';

const { listUsers, setUserAllocationColleges } = vi.hoisted(() => ({
  listUsers: vi.fn(),
  setUserAllocationColleges: vi.fn(),
}));

vi.mock('../api/client', () => ({
  listUsers,
  approveUser: vi.fn(),
  changeUserRole: vi.fn(),
  deleteUser: vi.fn(),
  toggleUserNotifications: vi.fn(),
}));
vi.mock('../api/userAllocationColleges', () => ({ setUserAllocationColleges }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const userRecord: AuthUser = {
  id: 7,
  smithId: 'staff7',
  username: 'staff7',
  firstName: 'Taylor',
  lastName: 'Staff',
  displayName: 'Taylor Staff',
  affiliation: 'staff',
  email: null,
  role: 'user',
  isApproved: true,
  receiveNotifications: false,
  defaultCampus: 'Smith College',
  lastLogin: null,
  createdAt: '',
  allocationColleges: ['MH'],
};

describe('Users allocation access', () => {
  it('updates college assignments while keeping the account role as user', async () => {
    const user = userEvent.setup();
    listUsers.mockResolvedValue([userRecord]);
    setUserAllocationColleges.mockImplementation(async (_id: number, colleges: string[]) => ({
      ...userRecord,
      allocationColleges: colleges,
    }));
    render(<Users />);

    const smithAccess = await screen.findByRole('checkbox', { name: 'SC allocation access for staff7' });
    expect(smithAccess).not.toBeChecked();
    await user.click(smithAccess);

    await waitFor(() => expect(setUserAllocationColleges).toHaveBeenCalledWith(7, ['MH', 'SC']));
    expect(await screen.findByRole('checkbox', { name: 'SC allocation access for staff7' })).toBeChecked();
    expect(screen.getByLabelText('Role for staff7')).toHaveValue('user');
  });
});
