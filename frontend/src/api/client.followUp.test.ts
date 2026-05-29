import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const get = vi.fn();
const patch = vi.fn();
const del = vi.fn();
const create = vi.fn(() => ({
  post,
  get,
  patch,
  delete: del,
  interceptors: {
    request: { use: vi.fn() },
    response: { use: vi.fn() },
  },
}));

vi.mock('axios', () => ({
  default: {
    create,
    post: vi.fn(),
  },
}));

describe('API client follow-up context', () => {
  beforeEach(() => {
    post.mockReset();
    get.mockReset();
    patch.mockReset();
    del.mockReset();
  });

  it('sends followUpContext with NL requests when supplied', async () => {
    const { askNl } = await import('./client');
    post.mockResolvedValueOnce({ data: { sql: 'SELECT 1', dataSource: 'folio' } });

    await askNl('include call numbers', 'Smith College', true, {
      source: 'ask',
      previousPrompt: 'original',
      previousSql: 'SELECT inst.title FROM inventory.instance__t inst',
      previousColumns: ['title'],
    });

    expect(post).toHaveBeenCalledWith('/nl', {
      prompt: 'include call numbers',
      campus: 'Smith College',
      includeSuggestions: true,
      followUpContext: {
        source: 'ask',
        previousPrompt: 'original',
        previousSql: 'SELECT inst.title FROM inventory.instance__t inst',
        previousColumns: ['title'],
      },
    });
  });

  it('configures a long enough timeout for AI generation to return backend timeout errors', async () => {
    await import('./client');

    expect(create).toHaveBeenCalledWith(expect.objectContaining({
      timeout: 180000,
    }));
  });
});
