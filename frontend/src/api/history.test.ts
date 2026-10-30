import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from './client';
import { fetchHistoryGroup, fetchHistoryItem } from './history';

vi.mock('./client', () => ({
  api: { get: vi.fn() },
}));

afterEach(() => vi.clearAllMocks());

describe('history API routes', () => {
  it('fetches a group by any member id with status and bounded page parameters', async () => {
    const response = { total: 4, offset: 2, limit: 2, items: [] };
    vi.mocked(api.get).mockResolvedValue({ data: response });

    await expect(fetchHistoryGroup('member-id', { status: 'completed', limit: 2, offset: 2 })).resolves.toEqual(response);
    expect(api.get).toHaveBeenCalledWith('/query/history/member-id/group', {
      params: { status: 'completed', limit: 2, offset: 2 },
    });
  });

  it('fetches one history item for a deep link', async () => {
    const response = { jobId: 'job-id', status: 'failed', errorMessage: 'Synthetic failure' };
    vi.mocked(api.get).mockResolvedValue({ data: response });

    await expect(fetchHistoryItem('job-id')).resolves.toEqual(response);
    expect(api.get).toHaveBeenCalledWith('/query/history/job-id');
  });
});
