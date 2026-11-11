import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import { useState } from 'react';
import MarcFieldFinderParameters from './MarcFieldFinderParameters';
import ReportParameterFields from './ReportParameterFields';
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

const campusLocationOptions = [
  { value: 'smith-browse', label: 'Smith College — Neilson Library — Browsing [BROWSE]' },
  { value: 'smith-stacks', label: 'Smith College — Young Science Library — Stacks [STACKS]' },
  { value: 'amherst-circulation', label: 'Amherst College — Frost Library — Circulation [CIRC]' },
  { value: 'amherst-reading-room', label: 'Amherst College — Archives & Special Collections — Reading Room [READ]' },
  { value: 'umass-periodicals', label: 'University Of Massachusetts — Du Bois Library — Periodicals [PER]' },
  { value: 'unassigned', label: 'Unassigned location [UNASSIGNED]' },
];

const baseValues = {
  locationIds: '11111111-1111-4111-8111-111111111111', locationBasis: 'effective_item', marcTag: '035',
  occurrenceCondition: 'missing', firstIndicator: 'blank', secondIndicator: 'char:9', subfieldCode: 'a',
  contentRule: 'any', searchValue: '', caseExact: 'false',
};

describe('MarcFieldFinderParameters', () => {
  it('enables campus selection for the MARC Field Finder Locations field', async () => {
    const user = userEvent.setup();
    render(
      <MarcFieldFinderParameters
        values={{ ...baseValues, locationIds: '' }}
        parameters={params}
        selectOptions={{ ...options, locationIds: campusLocationOptions }}
        onChange={() => {}}
      />,
    );

    await user.click(screen.getByRole('button', { name: 'Select locations' }));

    expect(screen.getByRole('button', { name: 'Select all Smith College (2)' })).toBeInTheDocument();
  });

  it('enables campus selection for the MARC missing-tag report Locations field', async () => {
    const user = userEvent.setup();
    render(
      <ReportParameterFields
        slug="marc-bibliographic-records-missing-tag"
        values={{ locationIds: '' }}
        parameters={[params[0]]}
        selectOptions={{ locationIds: campusLocationOptions }}
        serverFieldErrors={{}}
        onChange={() => {}}
      />,
    );

    await user.click(screen.getByRole('button', { name: 'Select locations' }));

    expect(screen.getByRole('button', { name: 'Select all Smith College (2)' })).toBeInTheDocument();
  });

  it('does not show campus controls for a non-location multiselect on another report', async () => {
    const user = userEvent.setup();
    render(
      <ReportParameterFields
        slug="other-report"
        values={{ fundIds: '' }}
        parameters={[{
          name: 'fundIds',
          type: 'multiselect',
          label: 'Funds',
          required: false,
          default: '',
          resolvedDefault: '',
          max_selections: 100,
        }]}
        selectOptions={{ fundIds: campusLocationOptions }}
        serverFieldErrors={{}}
        onChange={() => {}}
      />,
    );

    await user.click(screen.getByRole('button', { name: 'Select funds' }));

    expect(screen.getByRole('button', { name: 'Select all funds' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Select all Smith College (2)' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Clear Smith College' })).not.toBeInTheDocument();
  });

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

  it('announces MARC subfield coding and clears the warning when the text is corrected', () => {
    const values = { ...baseValues, contentRule: 'contains', searchValue: '‡a Women $2 local' };
    const props = { values, parameters: params, selectOptions: options, onChange: () => {} };
    const { rerender } = render(<MarcFieldFinderParameters {...props} />);

    const warning = screen.getByRole('status');
    expect(warning).toHaveTextContent(
      'This looks like MARC subfield coding (‡a). Enter only the text inside one subfield, and choose the subfield in the Subfield box. To match two subfields, use the MARC Field Combination Finder.',
    );
    const searchInput = screen.getByLabelText('Search text');
    expect(searchInput).toHaveAccessibleDescription(
      'This looks like MARC subfield coding (‡a). Enter only the text inside one subfield, and choose the subfield in the Subfield box. To match two subfields, use the MARC Field Combination Finder.',
    );
    expect(searchInput).not.toHaveAttribute('aria-invalid', 'true');

    rerender(<MarcFieldFinderParameters {...props} values={{ ...values, searchValue: 'Women' }} />);
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Search text')).not.toHaveAccessibleDescription();
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

    expect(screen.getByText(/MARC tag must be exactly three digits from 001 through 999/)).toBeInTheDocument();
    expect(screen.getByText(/Server rejected this MARC tag/)).toBeInTheDocument();
    expect(screen.queryByLabelText('First indicator')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Subfield code')).not.toBeInTheDocument();
  });

  it('reveals indicator and subfield controls after a valid MARC tag is entered', () => {
    const values = { ...baseValues, marcTag: '000' };
    const { rerender } = render(
      <MarcFieldFinderParameters values={values} parameters={params} selectOptions={options} onChange={() => {}} />,
    );
    expect(screen.queryByLabelText('First indicator')).not.toBeInTheDocument();

    rerender(
      <MarcFieldFinderParameters values={{ ...values, marcTag: '245' }} parameters={params} selectOptions={options} onChange={() => {}} />,
    );
    expect(screen.getByLabelText('First indicator')).toBeInTheDocument();
    expect(screen.getByLabelText('Subfield code')).toBeInTheDocument();
  });

  it('associates generic inline errors with their controls', () => {
    render(
      <MarcFieldFinderParameters
        values={{ ...baseValues, marcTag: '000' }}
        parameters={params}
        selectOptions={options}
        serverFieldErrors={{ marcTag: 'Server rejected this MARC tag.' }}
        onChange={() => {}}
      />,
    );

    const tag = screen.getByLabelText(/MARC tag/);
    expect(tag).toHaveAttribute('aria-invalid', 'true');
    expect(tag).toHaveAttribute('aria-describedby', 'marc-finder-marcTag-error');
  });
});
