import { describe, expect, it } from 'vitest';
import * as client from './client';

describe('reference cache API client', () => {
  it('exports a reference cache status fetcher', () => {
    expect(typeof client.fetchReferenceCacheStatus).toBe('function');
  });
});
