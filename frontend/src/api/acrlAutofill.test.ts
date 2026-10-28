import { afterEach, describe, expect, it, vi } from 'vitest';
import { runAcrlAutofill } from './acrl';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('./client', () => ({ api: { post } }));

afterEach(() => vi.clearAllMocks());

describe('runAcrlAutofill', () => {
  it('posts a blank-part question using the route sentinel and returns the suggestion response', async () => {
    const response = {
      value: 18438,
      queryId: 'acrl-60',
      queryVersion: 1,
      ranAt: '2026-10-28T12:34:56-04:00',
      asOf: null,
      fyStart: '2025-07-01',
      fyEnd: '2026-07-01',
      kind: 'dated' as const,
    };
    post.mockResolvedValue({ data: response });

    await expect(runAcrlAutofill(2026, 'survey', '60', '')).resolves.toEqual(response);
    expect(post).toHaveBeenCalledWith('/acrl/years/2026/autofill/survey/60/_');
  });
});
