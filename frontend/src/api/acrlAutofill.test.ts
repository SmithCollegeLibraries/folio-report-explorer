import { afterEach, describe, expect, it, vi } from 'vitest';
import { getAcrlAutofillMethods, runAcrlAutofill } from './acrl';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('./client', () => ({ api: { get, post } }));

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

  it('sends the optional week start in the body for a weekly fill', async () => {
    post.mockResolvedValue({ data: { value: 42 } });

    await runAcrlAutofill(2025, 'survey', '79c', '', '2025-02-09');

    expect(post).toHaveBeenCalledWith('/acrl/years/2025/autofill/survey/79c/_', { weekStart: '2025-02-09' });
  });
});

describe('getAcrlAutofillMethods', () => {
  it('gets the methods catalog from the ACRL methods route', async () => {
    const response = { methods: [] };
    get.mockResolvedValue({ data: response });

    await expect(getAcrlAutofillMethods()).resolves.toEqual(response);
    expect(get).toHaveBeenCalledWith('/acrl/autofill/methods');
  });
});
