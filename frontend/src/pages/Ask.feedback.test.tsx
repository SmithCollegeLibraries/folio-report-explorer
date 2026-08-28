import { describe, expect, it } from 'vitest';
import { buildQueryFeedbackInput, buildQueryReplacementInput } from './Ask';
import type { NlResponse } from '../types/schema';

describe('Ask trusted query feedback input', () => {
  it('sends only server-owned linkage identifiers, accuracy, and the optional note', () => {
    const result = {
      generationId: 'generation-1',
      sql: 'SELECT title FROM inventory.instance__t',
      route: 'exploratory_legacy_freeform',
      routeReason: 'unsupported_query_family',
      mode: 'exploratory',
      dataSource: 'folio',
      generationProvenance: 'ai_built',
    } as NlResponse;

    expect(buildQueryFeedbackInput(result, 'job-1', 'inaccurate', '  Wrong totals  ')).toEqual({
      generationId: 'generation-1',
      queryJobId: 'job-1',
      resultAccuracy: 'inaccurate',
      feedbackNote: 'Wrong totals',
    });
  });

  it('rejects feedback before both the generated report and completed job are linked', () => {
    expect(() => buildQueryFeedbackInput({} as NlResponse, 'job-1', 'accurate')).toThrow();
    expect(() => buildQueryFeedbackInput({ generationId: 'generation-1' } as NlResponse, '', 'accurate')).toThrow();
  });

  it('builds replacement requests from current scope only', () => {
    expect(buildQueryReplacementInput('Smith College')).toEqual({
      resolvedContext: { campus: 'Smith College' },
    });
    expect(buildQueryReplacementInput('All Colleges')).toEqual({ resolvedContext: {} });
  });
});
