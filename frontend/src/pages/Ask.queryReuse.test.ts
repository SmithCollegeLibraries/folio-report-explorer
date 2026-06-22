import { describe, expect, it } from 'vitest';
import { buildQueryReuseResolvedContext, formatQueryReuseMatchReason } from './Ask';

describe('Ask query reuse helpers', () => {
  it('sends campus context for scoped reuse checks', () => {
    expect(buildQueryReuseResolvedContext('Smith College')).toEqual({
      campus: 'Smith College',
    });
  });

  it('does not send campus context for all-colleges scope', () => {
    expect(buildQueryReuseResolvedContext('All Colleges')).toEqual({});
  });

  it('formats transparent match reason copy', () => {
    expect(formatQueryReuseMatchReason('completed_successfully')).toBe('Previous run completed successfully');
    expect(formatQueryReuseMatchReason('same_data_source')).toBe('Same data source');
    expect(formatQueryReuseMatchReason('same_campus')).toBe('Same campus or institution scope');
    expect(formatQueryReuseMatchReason('same_domain')).toBe('Same request domain');
    expect(formatQueryReuseMatchReason('custom_reason')).toBe('custom reason');
  });
});
