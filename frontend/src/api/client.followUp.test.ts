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
      allowExploratory: false,
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
      timeout: 300000,
    }));
  });

  it('sends explicit exploratory approval with NL requests', async () => {
    const { askNl } = await import('./client');
    post.mockResolvedValueOnce({ data: { sql: 'SELECT 1', dataSource: 'folio' } });

    await askNl('show vendor spend', 'Smith College', false, null, true);

    expect(post).toHaveBeenCalledWith('/nl', {
      prompt: 'show vendor spend',
      campus: 'Smith College',
      includeSuggestions: false,
      allowExploratory: true,
    });
  });

  it('saves query feedback with route and mode context', async () => {
    const { saveQueryFeedback } = await import('./client');
    post.mockResolvedValueOnce({
      data: { id: 1, message: 'saved', promptFingerprint: 'abc123', sqlHash: 'def456' },
    });

    await saveQueryFeedback({
      originalQuestion: 'show vendor spend',
      generatedSql: 'SELECT 1',
      route: 'exploratory_builder_intent',
      routeReason: 'user_approved_exploratory_generation',
      mode: 'exploratory',
      dataSource: 'folio',
      resultAccuracy: 'accurate',
      feedbackNote: 'Looks right',
    });

    expect(post).toHaveBeenCalledWith('/query-feedback', {
      originalQuestion: 'show vendor spend',
      generatedSql: 'SELECT 1',
      route: 'exploratory_builder_intent',
      routeReason: 'user_approved_exploratory_generation',
      mode: 'exploratory',
      dataSource: 'folio',
      resultAccuracy: 'accurate',
      feedbackNote: 'Looks right',
    });
  });
});
