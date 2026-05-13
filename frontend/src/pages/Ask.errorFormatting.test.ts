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
});