import { describe, expect, it } from 'vitest';
import source from './Settings.tsx?raw';

describe('Settings reference cache candidate review UI', () => {
  it('surfaces candidate review API errors', () => {
    expect(source).toContain('referenceReviewError');
    expect(source).toContain('setReferenceReviewError');
    expect(source).toContain('Review failed');
  });

  it('surfaces the last successful review decision', () => {
    expect(source).toContain('referenceReviewMessage');
    expect(source).toContain('setReferenceReviewMessage');
    expect(source).toContain('Review saved');
  });

  it('lets an enabled reference table be refreshed on demand', () => {
    expect(source).toContain('refreshReferenceTableMut');
    expect(source).toContain('refreshReferenceCacheTable');
    expect(source).toContain('Refresh table');
    expect(source).toContain('Refresh saved');
  });

  it('directs users to refresh enabled candidates manually', () => {
    expect(source).toContain('Refresh from the enabled table list');
  });
});
