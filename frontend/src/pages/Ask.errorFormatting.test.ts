import { describe, expect, it } from 'vitest';
import * as AskPage from './Ask';

describe('Ask error formatting', () => {
  it('keeps a successful AI-built response on the normal results path', () => {
    const result = {
      sql: 'SELECT title FROM inventory.instance__t',
      generationProvenance: 'ai_built' as const,
      needsClarification: true,
      validationSummary: { status: 'exhausted' as const, repairAttempts: 2 },
    };

    expect(AskPage.hasGeneratedSql?.(result)).toBe(true);
    expect(AskPage.shouldShowBlockingClarification?.(result)).toBe(false);
    expect(AskPage.shouldShowLegacyRecovery?.(result)).toBe(false);
    expect(AskPage.getAskResponseView?.(result)).toBe('success');
  });

  it('formats a terminal no-SQL generation failure as one compact retry message', () => {
    const message = AskPage.getAskTerminalFailureMessage?.({
      errorType: 'sql_generation_failed',
      message: 'Do not expose this repair diagnostic.',
      recoveryItems: ['Do not ask for a correction.'],
    });

    expect(message).toBe('Report Explorer could not safely run this report. Please retry.');
    expect(message).not.toMatch(/correction|clarification|refine|resolved/i);
    expect(AskPage.getAskResponseView?.({ errorType: 'sql_generation_failed' })).toBe('terminal_failure');
    expect(AskPage.getAskTerminalFailureAriaProps()).toEqual({
      role: 'alert',
      'aria-live': 'assertive',
    });
  });

  it('keeps clarification and recovery screens exclusively for no-SQL rollback responses', () => {
    expect(AskPage.getAskResponseView?.({ needsClarification: true })).toBe('legacy_clarification');
    expect(AskPage.getAskResponseView?.({
      validationSummary: { status: 'exhausted', repairAttempts: 2 },
    })).toBe('legacy_recovery');
  });

  it('routes both exhausted and rejected validation summaries through the no-SQL hard stop', () => {
    expect(typeof AskPage.isExploratoryValidationHardStop).toBe('function');
    expect(AskPage.isExploratoryValidationHardStop?.({ status: 'exhausted', repairAttempts: 2 })).toBe(true);
    expect(AskPage.isExploratoryValidationHardStop?.({ status: 'rejected', repairAttempts: 0 })).toBe(true);
    expect(AskPage.isExploratoryValidationHardStop?.({ status: 'validated', repairAttempts: 0 })).toBe(false);
  });

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

  it('keeps database cancellation distinct from AI timeout messaging', () => {
    const message = AskPage.formatNlError?.({
      isAxiosError: true,
      response: {
        status: 503,
        data: {
          errorType: 'database_cancelled',
          error: 'Database validation was cancelled before the query could run. Please retry the request.',
        },
      },
      message: 'Request failed with status code 503',
    });

    expect(message).toBe('Database validation was cancelled before the query could run. Please retry the request.');
    expect(message).not.toMatch(/AI|model|network/i);
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

  it('builds one clarified prompt from multiple clarification item choices', () => {
    const prompt = AskPage.buildBatchClarifiedPrompt?.('Compare MRBC and Josten collection holdings', [
      {
        term: 'MRBC',
        clarificationKey: 'location_alias.mrbc',
        selectedOption: {
          id: 'rare_ref',
          label: 'SC Rare Book Collection Reference',
          clarifiedPromptSuffix: 'Use inventory.location__t.name = SC Rare Book Collection Reference for MRBC.',
        },
        freeText: '',
      },
      {
        term: 'Josten collection',
        clarificationKey: 'collection_alias.josten',
        selectedOption: null,
        freeText: 'Search notes for Josten collection.',
      },
    ]);

    expect(prompt).toContain('Compare MRBC and Josten collection holdings');
    expect(prompt).toContain('Use inventory.location__t.name = SC Rare Book Collection Reference for MRBC.');
    expect(prompt).toContain('Search notes for Josten collection.');
  });

  it('preserves safe-probe clarified prompt suffixes so the resolver does not ask again', () => {
    const prompt = AskPage.buildBatchClarifiedPrompt?.('Generate titles for the Riverside collection', [
      {
        term: 'Riverside',
        clarificationKey: 'safe_probe.riverside.collection',
        selectedOption: {
          id: 'contributors',
          label: 'Search contributor/author fields for "Riverside"',
          clarifiedPromptSuffix: 'Search inventory.instance__t__contributors.contributors__name for Riverside.',
          resolvedFilter: {
            table: 'inventory.instance__t__contributors',
            column: 'contributors__name',
            operator: 'ILIKE',
            value: '%Riverside%',
          },
        },
        freeText: '',
      },
    ]);

    expect(prompt).toContain('Search inventory.instance__t__contributors.contributors__name for Riverside.');
  });

  it('builds batched clarification telemetry items', () => {
    const input = AskPage.buildBatchClarificationResolutionInput?.(
      'Compare MRBC and Josten collection holdings',
      'batch-1',
      [
        {
          term: 'MRBC',
          clarificationKey: 'location_alias.mrbc',
          confidence: 'ambiguous',
          options: [{ id: 'rare_ref', label: 'SC Rare Book Collection Reference', resolvedFilter: { table: 'inventory.location__t', value: 'SC Rare Book Collection Reference' } }],
          selectedOption: { id: 'rare_ref', label: 'SC Rare Book Collection Reference', resolvedFilter: { table: 'inventory.location__t', value: 'SC Rare Book Collection Reference' } },
          freeText: '',
        },
      ],
    );

    expect(input?.clarificationBatchId).toBe('batch-1');
    expect(input?.items).toHaveLength(1);
    expect(input?.items?.[0].term).toBe('MRBC');
    expect(input?.items?.[0].selectedValue).toBe('SC Rare Book Collection Reference');
  });

  it('formats safe resolver trace context without technical schema details', () => {
    const lines = AskPage.formatResolverTrace?.([
      {
        label: 'Checked locations, libraries, campuses, funds, material types, and other report filters for "Riverside"',
        status: 'no_match',
      },
      {
        label: 'Found possible match in contributor/author fields',
        status: 'found',
        detail: 'Riverside Press',
        technicalDetail: 'inventory.contributor__t.name',
      },
    ]);

    expect(lines).toEqual([
      'Checked locations, libraries, campuses, funds, material types, and other report filters for "Riverside": no match',
      'Found possible match in contributor/author fields: Riverside Press',
    ]);
    expect(lines?.join(' ')).not.toContain('inventory.contributor__t.name');
  });

  it('uses neutral generation and automatic-repair progress copy', () => {
    const progress = AskPage.getAskProgressCopy?.('generating');

    expect(progress?.title).toBe('Generating and validating your query');
    expect(progress?.steps).toContain('Preparing report context');
    expect(progress?.steps).toContain('Automatically repairing SQL that does not pass validation');
    expect(progress?.steps.join(' ')).not.toMatch(/clarification|follow-up question|resolver/i);
  });

  it('clamps the resizable Ask workspace split to usable pane widths', () => {
    expect(AskPage.clampAskWorkspaceSplit?.(20)).toBe(30);
    expect(AskPage.clampAskWorkspaceSplit?.(38)).toBe(38);
    expect(AskPage.clampAskWorkspaceSplit?.(82)).toBe(70);
  });

  it('clamps the resizable Ask AI panel to usable widths', () => {
    expect(AskPage.clampAskPanelWidth?.(240)).toBe(300);
    expect(AskPage.clampAskPanelWidth?.(360)).toBe(360);
    expect(AskPage.clampAskPanelWidth?.(640)).toBe(520);
  });

  it('uses separate result workspace tabs for results, follow-ups, and SQL', () => {
    expect(AskPage.ASK_RESULT_TABS?.map((tab) => tab.id)).toEqual(['results', 'followups', 'sql']);
    expect(AskPage.ASK_RESULT_TABS?.map((tab) => tab.label)).toEqual(['Results', 'Related follow-ups', 'Output SQL']);
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

  it('formats postgres integer overflow execution errors in readable language', () => {
    const message = AskPage.formatExecutionError?.(
      'SQLSTATE[22003]: Numeric value out of range: 7 ERROR: value "4253292441626" is out of range for type integer',
    );

    expect(message).toBe('Execution error: the generated SQL tried to convert a very large value to a 32-bit integer. Regenerate the query or change the cast to BIGINT.');
  });

  it('formats exploratory notices in staff-facing language', () => {
    const notice = AskPage.getExploratoryNoticeCopy?.({
      exploratoryNotice: {
        title: 'AI-assisted query',
        message: 'I could not match this request to a verified report pattern, so I built a best-effort query with the assumptions shown here.',
        detail: 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.',
        reason: 'unsupported_query_family',
      },
    });

    expect(notice?.title).toBe('AI-assisted query');
    expect(notice?.message).toContain('best-effort query');
    expect(notice?.message).not.toContain('canonical compiler path');
    expect(notice?.message).not.toMatch(/review.{0,80}sql|sql.{0,80}review/i);
    expect(notice?.detail).toContain('Similar wording');
  });

  it('falls back to user-facing exploratory notice copy when metadata is missing', () => {
    const notice = AskPage.getExploratoryNoticeCopy?.({
      mode: 'exploratory',
      repeatabilityWarning: 'This AI-assisted query may vary between runs until this request type is reviewed and promoted to a verified report pattern.',
    });

    expect(notice?.title).toBe('AI-assisted query');
    expect(notice?.message).toContain('verified report pattern');
    expect(notice?.message).not.toContain('canonical compiler');
    expect(notice?.message).not.toMatch(/review (the )?(results and )?sql/i);
  });

  it('keeps SQL inspection optional in progress copy', () => {
    const copy = AskPage.getAskProgressCopy?.('generating');

    expect(copy?.steps.join(' ')).not.toMatch(/review (the )?sql/i);
  });

  it('offers reuse choices without assigning SQL review to the user', () => {
    expect(AskPage.ASK_REUSE_CANDIDATE_MESSAGE).toContain('use the previous query');
    expect(AskPage.ASK_REUSE_CANDIDATE_MESSAGE).not.toMatch(/review (the )?sql/i);
  });

  it('does not treat advisory exploratory results as blocking clarifications', () => {
    expect(AskPage.shouldShowBlockingClarification?.({
      needsClarification: false,
      mode: 'exploratory',
      exploratoryNotice: {
        title: 'AI-assisted query',
        message: 'I could not match this request to a verified report pattern, so I built a best-effort query with the assumptions shown here.',
      },
    })).toBe(false);

    expect(AskPage.shouldShowBlockingClarification?.({
      needsClarification: true,
      clarificationItems: [
        {
          term: 'Duplaix',
          clarificationKey: 'safe_probe.duplaix.collection',
          question: 'Where should I search?',
          options: [],
        },
      ],
    })).toBe(true);
  });

  it('does not build fallback follow-up suggestions without generated SQL', () => {
    const result = AskPage.getEffectiveAskSuggestions?.(
      {
        suggestions: [],
      },
      'Show MRBC Reference Collection titles',
      'Smith College',
    );

    expect(result?.suggestions).toEqual([]);
    expect(result?.usingFallback).toBe(false);
  });

  it('uses fallback follow-up suggestions only after SQL exists', () => {
    const result = AskPage.getEffectiveAskSuggestions?.(
      {
        sql: 'SELECT * FROM inventory.instance__t LIMIT 10',
        suggestions: [],
      },
      'Show records in Neilson Library',
      'Smith College',
    );

    expect(result?.suggestions.length).toBeGreaterThan(0);
    expect(result?.usingFallback).toBe(true);
  });
});
