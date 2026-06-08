import { describe, expect, it } from 'vitest';
import * as client from './client';

describe('reference cache candidate API client', () => {
  it('exports a candidate review fetcher', () => {
    expect(typeof client.fetchReferenceCacheCandidates).toBe('function');
  });

  it('exports a candidate review updater', () => {
    expect(typeof client.reviewReferenceCacheCandidate).toBe('function');
  });

  it('exports a single-table reference cache refresher', () => {
    expect(typeof client.refreshReferenceCacheTable).toBe('function');
  });
});
