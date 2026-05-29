import { describe, expect, it } from 'vitest';
import { buildCurrentAskFollowUpContext, buildHistoryFollowUpContext } from './Ask';
import type { NlResponse } from '../types';

describe('Ask follow-up context helpers', () => {
  it('builds context from the current Ask result', () => {
    const result: NlResponse = {
      sql: 'SELECT inst.title FROM inventory.instance__t inst',
      dataSource: 'folio',
    };

    expect(buildCurrentAskFollowUpContext('Original MRBC list', result, ['title'])).toEqual({
      source: 'ask',
      previousPrompt: 'Original MRBC list',
      previousSql: 'SELECT inst.title FROM inventory.instance__t inst',
      previousColumns: ['title'],
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
});
