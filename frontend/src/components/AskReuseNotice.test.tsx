import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AskReuseNotice from './AskReuseNotice';

afterEach(cleanup);

describe('AskReuseNotice', () => {
  it.each([
    ['verified_global', 'verified_pattern', 'Verified pattern', 'Reused a compatible Verified pattern.'],
    ['same_user_accurate', 'ai_built', 'AI-built', 'Reused AI-built SQL you previously marked Accurate.'],
    ['administrator_approved', 'ai_built', 'AI-built', 'Reused administrator-approved AI-built SQL.'],
  ] as const)('explains %s trust without changing provenance', (reuseTrust, generationProvenance, provenanceLabel, copy) => {
    render(
      <AskReuseNotice
        generationProvenance={generationProvenance}
        provenanceLabel={provenanceLabel}
        reuseTrust={reuseTrust}
        onEditSql={vi.fn()}
        onGenerateFresh={vi.fn()}
      />,
    );

    expect(screen.getByText(copy)).toBeInTheDocument();
    expect(screen.getByText(provenanceLabel)).toBeInTheDocument();
    if (reuseTrust === 'administrator_approved') {
      expect(screen.queryByText('Verified pattern')).not.toBeInTheDocument();
    }
  });
});
