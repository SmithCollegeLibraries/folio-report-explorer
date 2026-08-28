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

    await askNl(
      'include call numbers',
      'Smith College',
      true,
      {
        source: 'ask',
        previousPrompt: 'original',
        previousSql: 'SELECT inst.title FROM inventory.instance__t inst',
        previousColumns: ['title'],
        parentGenerationId: 'generation-123',
      },
      false,
      'generation-123',
    );

    expect(post).toHaveBeenCalledWith('/nl', {
      prompt: 'include call numbers',
      campus: 'Smith College',
      includeSuggestions: true,
      allowExploratory: false,
      parentGenerationId: 'generation-123',
      followUpContext: {
        source: 'ask',
        previousPrompt: 'original',
        previousSql: 'SELECT inst.title FROM inventory.instance__t inst',
        previousColumns: ['title'],
        parentGenerationId: 'generation-123',
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

  it('saves query feedback with server-owned linkage identifiers', async () => {
    const { saveQueryFeedback } = await import('./client');
    post.mockResolvedValueOnce({
      data: { feedbackId: 1, resultAccuracy: 'accurate', reuseSuppressed: false, message: 'saved' },
    });

    await saveQueryFeedback({
      generationId: 'generation-1',
      queryJobId: 'job-1',
      resultAccuracy: 'accurate',
      feedbackNote: 'Looks right',
    });

    expect(post).toHaveBeenCalledWith('/query-feedback', {
      generationId: 'generation-1',
      queryJobId: 'job-1',
      resultAccuracy: 'accurate',
      feedbackNote: 'Looks right',
    });
  });

  it('checks for a previous successful query reuse candidate', async () => {
    const { fetchQueryReuseCandidate } = await import('./client');
    post.mockResolvedValueOnce({
      data: {
        match: {
          jobId: 'job-1',
          previousPrompt: 'How many items are in Smith College collection?',
          sql: 'SELECT COUNT(*) AS item_count FROM inventory.item__t',
          dataSource: 'folio',
          score: 98,
          matchReasons: ['completed_successfully', 'same_data_source', 'same_campus'],
          rowCount: 1,
          executionTimeMs: 42,
          completedAt: '2026-06-01 12:00:00',
        },
      },
    });

    await fetchQueryReuseCandidate({
      prompt: 'How many items are in the Smith College collection?',
      dataSource: 'folio',
      resolvedContext: { campus: 'Smith College', domain: 'inventory' },
    });

    expect(post).toHaveBeenCalledWith('/query/reuse-candidate', {
      prompt: 'How many items are in the Smith College collection?',
      dataSource: 'folio',
      resolvedContext: { campus: 'Smith College', domain: 'inventory' },
    });
  });

  it('submits accepted reuse metadata with rerun SQL', async () => {
    const { submitQuery } = await import('./client');
    post.mockResolvedValueOnce({ data: { jobId: 'new-job', status: 'pending' } });

    await submitQuery(
      'SELECT COUNT(*) AS item_count FROM inventory.item__t',
      {},
      'nl',
      'How many items are in the Smith College collection?',
      'folio',
      {
        outputMode: 'table',
        queryReuse: {
          candidateJobId: 'old-job',
          edited: true,
          score: 97,
        },
      },
    );

    expect(post).toHaveBeenCalledWith('/query/submit', {
      sql: 'SELECT COUNT(*) AS item_count FROM inventory.item__t',
      params: {},
      source: 'nl',
      name: 'How many items are in the Smith College collection?',
      dataSource: 'folio',
      outputMode: 'table',
      queryReuse: {
        candidateJobId: 'old-job',
        edited: true,
        score: 97,
      },
    });
  });

  it('submits resolved context with NL query jobs', async () => {
    const { submitQuery } = await import('./client');
    post.mockResolvedValueOnce({ data: { jobId: 'new-job', status: 'pending' } });

    await submitQuery(
      'SELECT COUNT(*) AS item_count FROM inventory.item__t',
      {},
      'nl',
      'How many items are in the collection?',
      'folio',
      {
        outputMode: 'table',
        resolvedContext: { campus: 'Smith College' },
      },
    );

    expect(post).toHaveBeenCalledWith('/query/submit', {
      sql: 'SELECT COUNT(*) AS item_count FROM inventory.item__t',
      params: {},
      source: 'nl',
      name: 'How many items are in the collection?',
      dataSource: 'folio',
      outputMode: 'table',
      resolvedContext: { campus: 'Smith College' },
    });
  });

  it('passes the opaque generation ID when submitting generated SQL', async () => {
    const { submitQuery } = await import('./client');
    post.mockResolvedValueOnce({ data: { jobId: 'new-job', status: 'pending' } });

    await submitQuery('SELECT 1', {}, 'nl', 'Show one', 'folio', {
      generationId: 'generation-123',
    });

    expect(post).toHaveBeenCalledWith('/query/submit', {
      sql: 'SELECT 1',
      params: {},
      source: 'nl',
      name: 'Show one',
      dataSource: 'folio',
      generationId: 'generation-123',
    });
  });

  it('records query reuse review decisions', async () => {
    const { recordQueryReuseDecision } = await import('./client');
    post.mockResolvedValueOnce({ data: { ok: true } });

    await recordQueryReuseDecision({
      decision: 'bypassed',
      candidateJobId: 'old-job',
      prompt: 'How many items are in the Smith College collection?',
    });

    expect(post).toHaveBeenCalledWith('/query/reuse-decision', {
      decision: 'bypassed',
      candidateJobId: 'old-job',
      prompt: 'How many items are in the Smith College collection?',
    });
  });
});
