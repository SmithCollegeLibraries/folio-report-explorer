import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { NlResponse } from '../types';
import { ExploratoryRecoveryPanel } from './ExploratoryRecoveryPanel';

const response: NlResponse = {
  mode: 'exploratory',
  attemptedPlan: 'Aggregate paid invoice distributions and circulation before comparing ROI.',
  assumptions: [{
    key: 'roi_formula',
    label: 'Return on investment',
    value: 'checkouts_per_dollar_with_cost_per_use',
    explanation: 'ROI is checkouts per dollar, with cost per checkout as a companion measure.',
    correctionExample: 'Use cost per checkout as ROI.',
    source: 'default',
  }],
  suggestions: ['Use cost per checkout as ROI.', 'Limit the request to one fiscal year.'],
  validationSummary: {
    status: 'exhausted',
    repairAttempts: 2,
    failureCategory: 'unknown_table',
  },
  recoveryContext: {
    originalQuestion: 'Compare investment and circulation ROI',
    campus: 'Smith College',
  },
};

afterEach(cleanup);

describe('ExploratoryRecoveryPanel', () => {
  it('preserves recovery context without claiming generated SQL or run controls exist', () => {
    render(<ExploratoryRecoveryPanel response={response} onRetry={() => undefined} onRefine={() => undefined} />);

    expect(screen.getByRole('heading', { name: 'The request is preserved' })).toBeInTheDocument();
    expect(screen.getByText('Aggregate paid invoice distributions and circulation before comparing ROI.')).toBeInTheDocument();
    expect(screen.queryByText(/safe failure category/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/missing table/i)).not.toBeInTheDocument();
    expect(screen.getByText('Return on investment')).toBeInTheDocument();
    expect(screen.getByText('ROI is checkouts per dollar, with cost per checkout as a companion measure.')).toBeInTheDocument();
    expect(screen.queryByText(/Generated SQL/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Run/i })).not.toBeInTheDocument();
  });

  it('retries the preserved question and refines it with a selected suggestion', () => {
    const onRetry = vi.fn();
    const onRefine = vi.fn();
    render(<ExploratoryRecoveryPanel response={response} onRetry={onRetry} onRefine={onRefine} />);

    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(onRetry).toHaveBeenCalledWith('Compare investment and circulation ROI');

    fireEvent.click(screen.getByRole('button', { name: 'Refine with: Use cost per checkout as ROI.' }));
    expect(onRefine).toHaveBeenCalledWith(
      'Compare investment and circulation ROI',
      'Use cost per checkout as ROI.',
    );
  });

  it('shows unmet requirement labels without exposing semantic validator internals', () => {
    const onRetry = vi.fn();
    const onRefine = vi.fn();
    render(
      <ExploratoryRecoveryPanel
        response={{
          ...response,
          recoveryItems: [
            'The report uses the requested campus scope.',
            'Aggregate spending before joining item-level circulation.',
          ],
          validationSummary: {
            status: 'exhausted',
            repairAttempts: 2,
            validatorStage: 'semantic_conformance',
            failureCategory: 'semantic_conformance_failed',
          },
        }}
        onRetry={onRetry}
        onRefine={onRefine}
      />,
    );

    expect(screen.getByRole('heading', { name: 'What still needs to be resolved' })).toBeInTheDocument();
    expect(screen.getByText('The report uses the requested campus scope.')).toBeInTheDocument();
    expect(screen.getByText('Aggregate spending before joining item-level circulation.')).toBeInTheDocument();
    expect(screen.queryByText(/safe failure category/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/missing table/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/semantic conformance/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(onRetry).toHaveBeenCalledWith('Compare investment and circulation ROI');
    fireEvent.click(screen.getByRole('button', { name: 'Refine with: Use cost per checkout as ROI.' }));
    expect(onRefine).toHaveBeenCalledWith(
      'Compare investment and circulation ROI',
      'Use cost per checkout as ROI.',
    );
    expect(screen.queryByText(/Generated SQL/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Run/i })).not.toBeInTheDocument();
  });

  it('renders rejected unsafe SQL with deterministic refinement actions and no SQL controls', () => {
    const onRefine = vi.fn();
    render(
      <ExploratoryRecoveryPanel
        response={{
          ...response,
          errorType: 'unsafe_generated_sql',
          suggestions: [],
          exploratoryPlan: { suggestions: [] },
          validationSummary: { status: 'rejected', repairAttempts: 0, failureCategory: 'non_select' },
        }}
        onRetry={() => undefined}
        onRefine={onRefine}
      />,
    );

    expect(screen.getByText(/nothing ran or changed/i)).toBeInTheDocument();
    expect(screen.getByText(/could not safely turn this request into a report/i)).toBeInTheDocument();
    expect(screen.queryByText(/safe failure category/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/^Non select$/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Refine with: Rephrase this as a read-only report.' }));
    expect(onRefine).toHaveBeenCalledWith(
      'Compare investment and circulation ROI',
      'Rephrase this as a read-only report.',
    );
    expect(screen.queryByText(/Generated SQL/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Run/i })).not.toBeInTheDocument();
  });

  it('renders connectivity guidance and retries the preserved question without SQL controls', () => {
    const onRetry = vi.fn();
    render(
      <ExploratoryRecoveryPanel
        response={{
          mode: 'exploratory',
          errorType: 'postgres_connectivity',
          message: 'I could not connect to the FOLIO reporting database. Connect to VPN and try again.',
          validationSummary: { status: 'rejected', repairAttempts: 0 },
          recoveryContext: {
            originalQuestion: 'Show available items',
            campus: 'Smith College',
          },
        }}
        onRetry={onRetry}
        onRefine={() => undefined}
      />,
    );

    expect(screen.getByText(/connect to the FOLIO reporting database/i)).toBeInTheDocument();
    expect(screen.getByText(/VPN/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(onRetry).toHaveBeenCalledWith('Show available items');
    expect(screen.queryByText(/Generated SQL/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Run/i })).not.toBeInTheDocument();
  });
});
