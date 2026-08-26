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

  it('renders the stored provenance for accepted and edited reused SQL', () => {
    for (const sql of [verifiedCandidate.sql, `${verifiedCandidate.sql} WHERE true`]) {
      const result = buildReusedNlResult(verifiedCandidate, sql);
      render(createElement(AskTrustNotice, {
        generationProvenance: result.generationProvenance,
        provenanceLabel: result.provenanceLabel,
      }));

      expect(screen.getByRole('heading', { name: 'Verified pattern' })).toBeInTheDocument();
      expect(screen.queryByText('AI-built')).not.toBeInTheDocument();
      cleanup();
    }
  });

  it('uses AI-built provenance for a legacy reuse record without stored provenance', () => {
    const { generationProvenance, provenanceLabel } = buildReusedNlResult({
      ...verifiedCandidate,
      generationProvenance: undefined,
      provenanceLabel: undefined,
    }, verifiedCandidate.sql);

    render(createElement(AskTrustNotice, { generationProvenance, provenanceLabel }));

    expect(screen.getByRole('heading', { name: 'AI-built' })).toBeInTheDocument();
    expect(screen.queryByText('Verified pattern')).not.toBeInTheDocument();
  });
});
