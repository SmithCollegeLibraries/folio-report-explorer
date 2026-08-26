import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ExploratoryAssumptionsPanel } from './ExploratoryAssumptionsPanel';

const assumptions = [
  {
    key: 'purchase_date_basis',
    label: 'Purchase date',
    value: 'payment_date',
    explanation: 'Purchases are assigned to the date the invoice was paid.',
    correctionExample: 'Use invoice date instead of payment date.',
    source: 'default' as const,
  },
  {
    key: 'campus_scope',
    label: 'Campus',
    value: 'Smith College',
    explanation: 'The request explicitly limits results to Smith College.',
    correctionExample: 'Use all campuses.',
    source: 'explicit' as const,
  },
];

afterEach(cleanup);

describe('ExploratoryAssumptionsPanel', () => {
  it('shows assumptions with an executable safety and preflight status', () => {
    render(<ExploratoryAssumptionsPanel assumptions={assumptions} repairCount={0} onCorrect={() => undefined} />);

    expect(screen.getByRole('heading', { name: 'Assumptions used' })).toBeInTheDocument();
    expect(screen.getByText('Executable SQL passed safety and preflight checks')).toBeInTheDocument();
    expect(screen.getByText('Purchase date')).toBeInTheDocument();
    expect(screen.getByText('payment_date')).toBeInTheDocument();
    expect(screen.getByText('Purchases are assigned to the date the invoice was paid.')).toBeInTheDocument();
    expect(screen.getByText('Default')).toBeInTheDocument();
    expect(screen.getByText('Explicit')).toBeInTheDocument();
  });

  it('reports automatic repairs without claiming semantic validation', () => {
    const onCorrect = vi.fn();
    render(<ExploratoryAssumptionsPanel assumptions={assumptions.slice(0, 1)} repairCount={2} onCorrect={onCorrect} />);

    expect(screen.getByText('Executable SQL passed safety and preflight checks after 2 automatic repairs')).toBeInTheDocument();
    expect(screen.queryByText(/validated/i)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Correct Purchase date assumption' }));
    expect(onCorrect).toHaveBeenCalledWith('Use invoice date instead of payment date.');
  });

  it('does not make semantic-advisory results look independently verified', () => {
    render(
      <ExploratoryAssumptionsPanel
        assumptions={assumptions.slice(0, 1)}
        repairCount={0}
        onCorrect={() => undefined}
      />,
    );

    expect(screen.getByText('Executable SQL passed safety and preflight checks')).toBeInTheDocument();
    expect(screen.queryByText(/semantic validation|validated against your request/i)).not.toBeInTheDocument();
  });

  it('shows successful report coverage in plain language beside the assumptions', () => {
    const { container } = render(
      <ExploratoryAssumptionsPanel
        assumptions={assumptions.slice(0, 1)}
        repairCount={0}
        onCorrect={() => undefined}
        reportDisclosures={[
          'Physical purchases and current Smith physical holdings only.',
          'Exact receiving links are preferred; fallback-linked copies and percentage are shown.',
        ]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Report coverage' })).toBeInTheDocument();
    expect(screen.getByText('Physical purchases and current Smith physical holdings only.')).toBeInTheDocument();
    expect(screen.getByText('Exact receiving links are preferred; fallback-linked copies and percentage are shown.')).toBeInTheDocument();
    expect(container.textContent).not.toMatch(/CTE|join grain|schema cache|database error|raw SQL/i);
  });
});
