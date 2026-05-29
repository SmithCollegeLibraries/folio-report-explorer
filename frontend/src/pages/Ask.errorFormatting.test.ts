import { describe, expect, it } from 'vitest';
import * as AskPage from './Ask';

describe('Ask error formatting', () => {
  it('surfaces postgres preflight failures as query validation errors instead of generic AI errors', () => {
    expect(typeof AskPage.formatNlError).toBe('function');

    const message = AskPage.formatNlError?.({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          error: 'operator does not exist: jsonb !~~* unknown',
        },
      },
      message: 'Request failed with status code 422',
    });

    expect(message).toBe('Query validation failed: operator does not exist: jsonb !~~* unknown');
  });

  it('surfaces AI timeout failures as transient service issues', () => {
    const message = AskPage.formatNlError?.({
      isAxiosError: true,
      response: {
        status: 504,
        data: {
          errorType: 'ai_timeout',
          error: 'The AI request timed out. Your question is fine; the model or network took too long to respond. Please try again, or simplify the request if it keeps happening.',
        },
      },
      message: 'Request failed with status code 504',
    });

    expect(message).toBe('The AI request timed out. Your question is fine; the model or network took too long to respond. Please try again, or simplify the request if it keeps happening.');
  });

  it('surfaces client-side Axios timeouts as transient AI service issues', () => {
    const message = AskPage.formatNlError?.({
      isAxiosError: true,
      code: 'ECONNABORTED',
      message: 'timeout of 60000ms exceeded',
    });

    expect(message).toBe('The AI request timed out. Your question is fine; the model or network took too long to respond. Please try again, or simplify the request if it keeps happening.');
  });

  it('does not block clarification continuation when telemetry persistence fails', async () => {
    const result = await AskPage.saveClarificationResolutionBestEffort?.(
      async () => {
        throw new Error('table missing');
      },
      { originalQuestion: 'MRBC Reference', clarificationKey: 'location_alias.mrbc_reference' },
    );

    expect(result).toBe(false);
  });

  it('explains grouped title SQL validation failures in user-facing language', () => {
    const message = AskPage.formatQuerySubmitError?.({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          error: 'column "ii.title" must appear in the GROUP BY clause or be used in an aggregate function',
        },
      },
      message: 'Request failed with status code 422',
    });

    expect(message).toBe('Query validation failed: the generated SQL mixed title columns with grouped results incorrectly. I did not run it. Please regenerate the query; titles should come from inventory.instance__t.title and every selected non-aggregate column must be grouped.');
  });

  it('does not show raw Axios text for query submit 500s without a backend message', () => {
    const message = AskPage.formatQuerySubmitError?.({
      isAxiosError: true,
      response: {
        status: 500,
        data: {},
      },
      message: 'Request failed with status code 500',
    });

    expect(message).toBe('Submit error: the server hit an internal error while preparing the query job. You did not do anything wrong. Please try again, and contact support if it repeats.');
  });
});
