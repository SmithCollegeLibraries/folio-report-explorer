import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const get = vi.fn();
const patch = vi.fn();
const del = vi.fn();

vi.mock('axios', () => ({
  default: {
    create: vi.fn(() => ({
      post,
      get,
      patch,
      delete: del,
      interceptors: {
        request: { use: vi.fn() },
        response: { use: vi.fn() },
      },
    })),
    post: vi.fn(),
  },
}));

describe('promoteToReport status handling', () => {
  beforeEach(() => {
    post.mockReset();
    get.mockReset();
    patch.mockReset();
    del.mockReset();
  });

  it.each([
    [201, 'newly created'],
    [200, 'already existing'],
  ])('preserves the response body and exposes HTTP %i for a %s report', async (status) => {
    const body = { id: 37, slug: 'synthetic-report', name: 'Synthetic report' };
    post.mockResolvedValueOnce({ data: body, status });
    const { promoteToReport } = await import('./client');

    const result = await promoteToReport(12);

    expect(post).toHaveBeenCalledWith('/saved/12/promote');
    expect(result).toEqual({ data: body, status });
    expect(result.data).toEqual({ id: 37, slug: 'synthetic-report', name: 'Synthetic report' });
  });
});
