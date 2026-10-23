import { api } from './client';
import type { AuthUser } from '../types';

export async function setUserAllocationColleges(id: number, allocationColleges: string[]): Promise<AuthUser> {
  const { data } = await api.put(`/users/${id}/allocation-colleges`, { allocationColleges });
  return data;
}
