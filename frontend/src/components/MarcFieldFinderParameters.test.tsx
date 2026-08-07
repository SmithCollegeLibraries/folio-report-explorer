import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import { useState } from 'react';
import MarcFieldFinderParameters from './MarcFieldFinderParameters';
import type { ReportParam } from '../types';

afterEach(cleanup);

const params: ReportParam[] = [
  { name: 'locationIds', type: 'multiselect', label: 'Locations', required: true, default: '', resolvedDefault: '', max_selections: 100 },
  { name: 'locationBasis', type: 'select', label: 'Location basis', required: true, default: 'effective_item', resolvedDefault: 'effective_item' },
  { name: 'marcTag', type: 'text', label: 'MARC tag', required: true, default: '', resolvedDefault: '' },
  { name: 'occurrenceCondition', type: 'select', label: 'Occurrence condition', required: true, default: 'has', resolvedDefault: 'has' },
  { name: 'firstIndicator', type: 'select', label: 'First indicator', required: true, default: 'any', resolvedDefault: 'any' },
  { name: 'secondIndicator', type: 'select', label: 'Second indicator', required: true, default: 'any', resolvedDefault: 'any' },
  { name: 'subfieldCode', type: 'text', label: 'Subfield code', required: false, default: '', resolvedDefault: '' },
  { name: 'contentRule', type: 'select', label: 'Content rule', required: true, default: 'any', resolvedDefault: 'any' },
  { name: 'searchValue', type: 'text', label: 'Search text', required: false, default: '', resolvedDefault: '' },
  { name: 'caseExact', type: 'select', label: 'Case matching', required: true, default: 'false', resolvedDefault: 'false' },
];

const options = {
  locationIds: [{ value: '11111111-1111-4111-8111-111111111111', label: 'Smith — SC Internet [SCINT]' }],
  locationBasis: [{ value: 'effective_item', label: 'Effective item' }, { value: 'permanent_item', label: 'Permanent item' }],
  occurrenceCondition: [{ value: 'has', label: 'Has matching occurrence' }, { value: 'missing', label: 'Missing matching occurrence' }],
  contentRule: [
    { value: 'any', label: 'Any' }, { value: 'contains', label: 'Contains' },
    { value: 'not_begins', label: 'Does not begin with' },
  ],
  caseExact: [{ value: 'false', label: 'Case-insensitive' }, { value: 'true', label: 'Case-exact' }],
};

const baseValues = {
  locationIds: '11111111-1111-4111-8111-111111111111', locationBasis: 'effective_item', marcTag: '035',
  occurrenceCondition: 'missing', firstIndicator: 'blank', secondIndicator: 'char:9', subfieldCode: 'a',
  contentRule: 'any', searchValue: '', caseExact: 'false',
};

describe('MarcFieldFinderParameters', () => {
  it('renders the conditional controls and interpretation summary', () => {
    render(<MarcFieldFinderParameters values={baseValues} parameters={params} selectOptions={options} onChange={() => {}} />);

    expect(screen.getByRole('button', { name: /location selected/i })).toBeInTheDocument();
    expect(screen.getByLabelText('First indicator')).toBeInTheDocument();
    expect(screen.getByLabelText('Second indicator')).toBeInTheDocument();
    expect(screen.getByLabelText('MARC finder interpretation')).toHaveTextContent(
      'no field row matches: tag 035, first indicator #, second indicator 9, subfield a',
    );
    expect(screen.queryByLabelText('Search text')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Case matching')).not.toBeInTheDocument();
  });

  it('shows text controls only for text-consuming content rules', async () => {
    const user = userEvent.setup();
    function Harness() {
      const [values, setValues] = useState(baseValues);
      return <MarcFieldFinderParameters values={values} parameters={params} selectOptions={options} onChange={(name, value) => setValues((current) => ({ ...current, [name]: value }))} />;
    }
    render(<Harness />);

    await user.selectOptions(screen.getByLabelText(/Content rule/), 'contains');
    expect(screen.getByLabelText('Search text')).toBeInTheDocument();
    expect(screen.getByLabelText(/Case matching/)).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Contains' })).toBeInTheDocument();
  });

  it('clears stale text values when the rule no longer consumes text', () => {
    const changes: Array<[string, string]> = [];
    const values = { ...baseValues, contentRule: 'any', searchValue: 'stale', caseExact: 'true' };
    render(<MarcFieldFinderParameters values={values} parameters={params} selectOptions={options} onChange={(name, value) => changes.push([name, value])} />);

    expect(changes).toEqual(expect.arrayContaining([
      ['searchValue', ''],
      ['caseExact', 'false'],
    ]));
  });

  it('displays matching client and server field errors beside controls', () => {
    render(
      <MarcFieldFinderParameters
        values={{ ...baseValues, marcTag: '000' }}
        parameters={params}
        selectOptions={options}
        serverFieldErrors={{ marcTag: 'Server rejected this MARC tag.' }}
        onChange={() => {}}
      />,
    );

    expect(screen.getByText('MARC tag must be exactly three digits from 001 through 999.')).toBeInTheDocument();
    expect(screen.getByText('Server rejected this MARC tag.')).toBeInTheDocument();
  });
});
