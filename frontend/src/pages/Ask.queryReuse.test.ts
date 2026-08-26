import { cleanup, render, screen } from '@testing-library/react';
import { createElement } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import AskTrustNotice from '../components/AskTrustNotice';
import type { QueryReuseCandidate } from '../types';
import { buildReusedNlResult, buildQueryReuseResolvedContext, formatQueryReuseMatchReason } from './Ask';

const verifiedCandidate: QueryReuseCandidate = {
  jobId: 'job-verified',
  previousPrompt: 'Count inventory items',
  sql: 'SELECT COUNT(*) FROM inventory.item__t',
  dataSource: 'folio',
  score: 100,
  matchReasons: ['completed_successfully'],
  rowCount: 1,
  executionTimeMs: 20,
  completedAt: '2026-08-26 12:00:00',
  generationProvenance: 'verified_pattern',
  provenanceLabel: 'Verified pattern',
};

afterEach(cleanup);

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

  it('renders truthful provenance for unchanged, edited, stored-AI, and legacy reused SQL', () => {
    const cases = [
      {
        name: 'unchanged verified SQL',
        candidate: verifiedCandidate,
        sql: verifiedCandidate.sql,
        label: 'Verified pattern',
        absentLabel: 'AI-built',
      },
      {
        name: 'edited verified SQL',
        candidate: verifiedCandidate,
        sql: `${verifiedCandidate.sql} WHERE true`,
        label: 'AI-built',
        absentLabel: 'Verified pattern',
      },
      {
        name: 'unchanged stored AI-built SQL',
        candidate: {
          ...verifiedCandidate,
          generationProvenance: 'ai_built' as const,
          provenanceLabel: 'AI-built' as const,
        },
        sql: verifiedCandidate.sql,
        label: 'AI-built',
        absentLabel: 'Verified pattern',
      },
      {
        name: 'legacy SQL without stored provenance',
        candidate: {
          ...verifiedCandidate,
          generationProvenance: undefined,
          provenanceLabel: undefined,
        },
        sql: verifiedCandidate.sql,
        label: 'AI-built',
        absentLabel: 'Verified pattern',
      },
    ];

    for (const testCase of cases) {
      const result = buildReusedNlResult(testCase.candidate, testCase.sql);
      render(createElement(AskTrustNotice, {
        generationProvenance: result.generationProvenance,
        provenanceLabel: result.provenanceLabel,
      }));

      expect(screen.getByRole('heading', { name: testCase.label })).toBeInTheDocument();
      expect(screen.queryByText(testCase.absentLabel)).not.toBeInTheDocument();
      cleanup();
    }
  });
});
