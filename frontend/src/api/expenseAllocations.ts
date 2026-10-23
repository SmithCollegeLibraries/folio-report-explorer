import { api } from './client';
import type { ExpenseAllocation } from '../types';

export async function listAllocations(
  fiscalYear: number | undefined,
  prefix: string,
): Promise<{ items: ExpenseAllocation[]; years: number[] }> {
  const { data } = await api.get('/local/allocations', { params: { fiscalYear, prefix } });
  return data;
}

export async function listAllocationYears(prefix: string): Promise<number[]> {
  const { data } = await api.get('/local/allocations/years', { params: { prefix } });
  return data.years || [];
}

export async function upsertAllocations(
  fiscalYear: number,
  prefix: string,
  payload:
    | { code: string; amount: number }
    | { rows: Array<{ expense_class_code: string; allocation_amount: number }> }
    | { pastedData: string },
): Promise<{ success: boolean; inserted: number; updated: number }> {
  const { data } = await api.post('/local/allocations', { fiscalYear, prefix, ...payload });
  return data;
}

export async function deleteAllocation(id: number, prefix: string): Promise<void> {
  await api.delete('/local/allocations/' + id, { params: { prefix } });
}

export async function copyAllocationYear(
  fiscalYear: number,
  prefix: string,
): Promise<{ success: boolean; copied: number; skipped: number; sourceYear: number }> {
  const { data } = await api.post('/local/allocations/copy-year', { fiscalYear, prefix });
  return data;
}
