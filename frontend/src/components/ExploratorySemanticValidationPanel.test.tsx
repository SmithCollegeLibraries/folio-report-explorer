import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { SemanticValidation } from '../types';
import { ExploratorySemanticValidationPanel } from './ExploratorySemanticValidationPanel';

afterEach(cleanup);

describe('ExploratorySemanticValidationPanel', () => {
  it('shows the plain-language requirements checked for a validated result', () => {
    const validation: SemanticValidation = {
      status: 'validated',
      contractVersion: 1,
      checkedRequirements: [
        { key: 'purchase_date_basis', label: 'Purchases use payment date for the last five years.' },
        { key: 'spend_grain', label: 'Spending is aggregated before item-level circulation is joined.' },
      ],
    };

    render(<ExploratorySemanticValidationPanel validation={validation} />);

    expect(screen.getByRole('heading', { name: 'Validated against your request' })).toBeInTheDocument();
    expect(screen.getByRole('list')).toBeInTheDocument();
    expect(screen.getByText('Purchases use payment date for the last five years.')).toBeInTheDocument();
    expect(screen.getByText('Spending is aggregated before item-level circulation is joined.')).toBeInTheDocument();
    expect(screen.queryByText(/SQL fragment|validator stage|evidence/i)).not.toBeInTheDocument();
  });

  it('renders nothing when no requirements were checked', () => {
    const { container } = render(
      <ExploratorySemanticValidationPanel
        validation={{ status: 'validated', contractVersion: 1, checkedRequirements: [] }}
      />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});
