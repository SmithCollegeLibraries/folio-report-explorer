import { describe, expect, it } from 'vitest';
import {
  buildCurrentAskFollowUpContext,
  buildGeneratedQuerySubmitOptions,
  buildHistoryFollowUpContext,
  buildQueryFeedbackInput,
} from './Ask';
import type { NlResponse } from '../types';

describe('Ask follow-up context helpers', () => {
  it('builds context from the current Ask result', () => {
    const result: NlResponse = {
      sql: 'SELECT inst.title FROM inventory.instance__t inst',
      dataSource: 'folio',
      generationId: 'generation-123',
      assumptions: [{
        key: 'purchase_date_basis',
        label: 'Purchase date',
        value: 'payment_date',
        explanation: 'Purchases use the invoice payment date.',
        correctionExample: 'Use invoice date instead of payment date.',
        source: 'default',
      }],
    };

    expect(buildCurrentAskFollowUpContext('Original MRBC list', result, ['title'])).toEqual({
      source: 'ask',
      previousPrompt: 'Original MRBC list',
      previousSql: 'SELECT inst.title FROM inventory.instance__t inst',
      previousColumns: ['title'],
      previousAssumptions: result.assumptions,
      parentGenerationId: 'generation-123',
    });
  });

  it('does not build current Ask context without SQL', () => {
    expect(buildCurrentAskFollowUpContext('Original MRBC list', {}, ['title'])).toBeNull();
  });

  it('builds context from a history job id', () => {
    expect(buildHistoryFollowUpContext('job-123')).toEqual({
      source: 'history',
      jobId: 'job-123',
    });
  });

  it('builds query feedback input with route and exploratory mode metadata', () => {
    const result: NlResponse = {
      sql: 'SELECT 1',
      dataSource: 'folio',
      route: 'exploratory_builder_intent',
      routeReason: 'user_approved_exploratory_generation',
      mode: 'exploratory',
    };

    expect(buildQueryFeedbackInput('  Show vendor spend  ', result, 'inaccurate', '  Missing fund filter  ')).toEqual({
      originalQuestion: 'Show vendor spend',
      generatedSql: 'SELECT 1',
      route: 'exploratory_builder_intent',
      routeReason: 'user_approved_exploratory_generation',
      mode: 'exploratory',
      dataSource: 'folio',
      resultAccuracy: 'inaccurate',
      feedbackNote: 'Missing fund filter',
    });
  });

  it('keeps the server generation ID on generated query submissions and reruns', () => {
    expect(buildGeneratedQuerySubmitOptions(
      { sql: 'SELECT 1', generationId: 'generation-123' },
      'table',
      { campus: 'Smith College' },
    )).toEqual({
      outputMode: 'table',
      resolvedContext: { campus: 'Smith College' },
      generationId: 'generation-123',
    });
  });
});
