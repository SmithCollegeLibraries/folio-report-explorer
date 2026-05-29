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
});
