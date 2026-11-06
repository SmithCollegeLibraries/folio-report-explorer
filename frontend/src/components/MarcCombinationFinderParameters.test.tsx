import { createElement, useState } from 'react';
import type { ComponentType } from 'react';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import type { ReportParam } from '../types';

type Props = {
  values: Record<string, string>;
  parameters: ReportParam[];
  selectOptions?: Record<string, Array<{ value: string; label: string }>>;
  serverFieldErrors: Record<string, string>;
  onChange: (name: string, value: string) => void;
};

const parameterModules = import.meta.glob('./MarcCombinationFinderParameters.tsx', { eager: true }) as Record<
  string,
  { default?: ComponentType<Props> }
>;

function combinationPanel(): ComponentType<Props> {
  const component = parameterModules['./MarcCombinationFinderParameters.tsx']?.default;
  expect(component).toBeDefined();
  return component as ComponentType<Props>;
}

function parameterDefinitions(): ReportParam[] {
  const definitions: ReportParam[] = [
    { name: 'locationIds', type: 'multiselect', label: 'Locations', required: false, default: '', resolvedDefault: '', max_selections: 100 },
    { name: 'locationBasis', type: 'select', label: 'Location basis', required: true, default: 'effective_item', resolvedDefault: 'effective_item' },
    { name: 'combine', type: 'select', label: 'Combine conditions', required: true, default: 'all', resolvedDefault: 'all' },
  ];
  for (let slot = 1; slot <= 5; slot++) {
    const fields: Array<[string, ReportParam['type'], string, boolean]> = [
      ['marcTag', 'text', '', slot === 1],
      ['occurrenceCondition', 'select', 'has', false],
      ['firstIndicator', 'select', 'any', false],
      ['secondIndicator', 'select', 'any', false],
      ['subfieldCode', 'text', '', false],
      ['contentRule', 'select', 'any', false],
      ['searchValue', 'text', '', false],
      ['caseExact', 'select', 'false', false],
    ];
    for (const [field, type, defaultValue, required] of fields) {
      definitions.push({
        name: `c${slot}_${field}`,
        type,
        label: `Condition ${slot} ${field}`,
        required,
        default: defaultValue,
        resolvedDefault: defaultValue,
      });
    }
  }
  return definitions;
}

function values(overrides: Record<string, string> = {}): Record<string, string> {
  const result: Record<string, string> = {
    locationIds: '11111111-1111-4111-8111-111111111111',
    locationBasis: 'effective_item',
    combine: 'all',
  };
  for (let slot = 1; slot <= 5; slot++) {
    Object.assign(result, {
      [`c${slot}_marcTag`]: '',
      [`c${slot}_occurrenceCondition`]: '',
      [`c${slot}_firstIndicator`]: '',
      [`c${slot}_secondIndicator`]: '',
      [`c${slot}_subfieldCode`]: '',
      [`c${slot}_contentRule`]: '',
      [`c${slot}_searchValue`]: '',
      [`c${slot}_caseExact`]: '',
    });
  }
  Object.assign(result, {
    c1_marcTag: '655',
    c1_occurrenceCondition: 'has',
    c1_firstIndicator: 'char:7',
    c1_secondIndicator: 'any',
    c1_subfieldCode: 'a',
    c1_contentRule: 'any',
    c1_searchValue: '',
    c1_caseExact: 'false',
  });
  return { ...result, ...overrides };
}

const selectOptions = {
  locationIds: [{ value: '11111111-1111-4111-8111-111111111111', label: 'Smith — Main [MAIN]' }],
  locationBasis: [
    { value: 'effective_item', label: 'Effective item' },
    { value: 'permanent_item', label: 'Permanent item' },
    { value: 'holdings_no_items', label: 'Holdings with no items (Smith)' },
    { value: 'no_holdings', label: 'Instances with no holdings (Smith)' },
  ],
  combine: [{ value: 'all', label: 'All conditions (AND)' }, { value: 'any', label: 'Any condition (OR)' }],
  c1_occurrenceCondition: [{ value: 'has', label: 'Has' }, { value: 'missing', label: 'Is missing' }],
  c2_occurrenceCondition: [{ value: 'has', label: 'Has' }, { value: 'missing', label: 'Is missing' }],
  c1_firstIndicator: [{ value: 'any', label: 'Any' }, { value: 'char:7', label: '7' }],
  c1_secondIndicator: [{ value: 'any', label: 'Any' }],
  c1_contentRule: [{ value: 'any', label: 'Any' }, { value: 'contains', label: 'Contains' }],
  c2_contentRule: [{ value: 'any', label: 'Any' }, { value: 'contains', label: 'Contains' }],
  c1_caseExact: [{ value: 'false', label: 'Case-insensitive' }, { value: 'true', label: 'Case-exact' }],
  c2_caseExact: [{ value: 'false', label: 'Case-insensitive' }, { value: 'true', label: 'Case-exact' }],
};

afterEach(cleanup);

function renderPanel(initial: Record<string, string>, serverFieldErrors: Record<string, string> = {}) {
  const Panel = combinationPanel();
  function Harness() {
    const [current, setCurrent] = useState(initial);
    return createElement(Panel, {
      values: current,
      parameters: parameterDefinitions(),
      selectOptions,
      serverFieldErrors,
      onChange: (name, value) => setCurrent((previous) => ({ ...previous, [name]: value })),
    });
  }
  return render(createElement(Harness));
}

describe('MarcCombinationFinderParameters', () => {
  it('shows item locations only for the item search and switches to each Smith record scope', async () => {
    const user = userEvent.setup();
    renderPanel(values());

    expect(screen.getByRole('radio', { name: 'Items at selected locations' })).toBeChecked();
    expect(screen.getByLabelText('Locations')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: /Location basis/ }).querySelectorAll('option')).toHaveLength(3);

    await user.click(screen.getByRole('radio', { name: 'Holdings with no items (Smith)' }));
    expect(screen.queryByLabelText('Locations')).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: /Location basis/ })).not.toBeInTheDocument();
    expect(screen.getByLabelText('MARC combination interpretation')).toHaveTextContent('Holdings with no items (Smith)');

    await user.click(screen.getByRole('radio', { name: 'Instances with no holdings (Smith)' }));
    expect(screen.getByLabelText('MARC combination interpretation')).toHaveTextContent('Instances with no holdings (Smith)');

    await user.click(screen.getByRole('radio', { name: 'Items at selected locations' }));
    expect(screen.getByLabelText('Locations')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: /Location basis/ })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Items at selected locations' })).toBeChecked();
  });

  it('adds condition cards through five and then prevents a sixth', async () => {
    const user = userEvent.setup();
    renderPanel(values());

    expect(screen.getByRole('heading', { name: 'Condition 1' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Add condition' }));
    expect(screen.getByRole('heading', { name: 'Condition 2' })).toBeInTheDocument();
    await user.type(screen.getByLabelText('Condition 2 MARC tag'), '563');
    await user.click(screen.getByRole('button', { name: 'Add condition' }));
    await user.type(screen.getByLabelText('Condition 3 MARC tag'), '856');
    await user.click(screen.getByRole('button', { name: 'Add condition' }));
    await user.type(screen.getByLabelText('Condition 4 MARC tag'), '245');
    await user.click(screen.getByRole('button', { name: 'Add condition' }));
    expect(screen.getByRole('heading', { name: 'Condition 5' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add condition' })).toBeDisabled();
  });

  it('prevents adding another card while a visible condition has no tag', async () => {
    const user = userEvent.setup();
    renderPanel(values());

    await user.click(screen.getByRole('button', { name: 'Add condition' }));
    const addButton = screen.getByRole('button', { name: 'Add condition' });
    expect.soft(addButton).toBeDisabled();
    await user.click(addButton);
    expect.soft(screen.queryByRole('heading', { name: 'Condition 3' })).not.toBeInTheDocument();
  });

  it('preserves configured condition values when the empty card receives a tag', async () => {
    const user = userEvent.setup();
    renderPanel(values({
      c1_marcTag: '',
      c1_occurrenceCondition: 'missing',
      c1_firstIndicator: 'char:7',
      c1_secondIndicator: 'blank',
      c1_subfieldCode: 'a',
      c1_contentRule: 'contains',
      c1_searchValue: 'Needle',
      c1_caseExact: 'true',
    }));

    await user.type(screen.getByLabelText('Condition 1 MARC tag'), '655');

    expect.soft(screen.getByLabelText('Condition 1 occurrence')).toHaveValue('missing');
    expect.soft(screen.getByLabelText('Condition 1 first indicator')).toHaveValue('char:7');
    expect.soft(screen.getByLabelText('Condition 1 second indicator')).toHaveValue('blank');
    expect.soft(screen.getByLabelText('Condition 1 subfield code')).toHaveValue('a');
    expect.soft(screen.getByLabelText('Condition 1 content rule')).toHaveValue('contains');
    const searchInput = screen.queryByLabelText('Condition 1 search text');
    const caseMatching = screen.queryByLabelText('Condition 1 case matching');
    expect.soft(searchInput).not.toBeNull();
    expect.soft(caseMatching).not.toBeNull();
    if (searchInput) expect.soft(searchInput).toHaveValue('Needle');
    if (caseMatching) expect.soft(caseMatching).toHaveValue('true');
  });

  it('removes a middle slot by compacting later values and clearing the last slot', async () => {
    const user = userEvent.setup();
    renderPanel(values({ c2_marcTag: '563', c3_marcTag: '856' }));

    await user.click(screen.getByRole('button', { name: 'Remove condition 2' }));
    expect(screen.getByLabelText('Condition 2 MARC tag')).toHaveValue('856');
    expect(screen.queryByRole('heading', { name: 'Condition 3' })).not.toBeInTheDocument();
  });

  it('allows removing slot one only while another active condition remains', async () => {
    const user = userEvent.setup();
    const view = renderPanel(values());
    expect(screen.getByRole('button', { name: 'Remove condition 1' })).toBeDisabled();
    view.unmount();

    renderPanel(values({ c2_marcTag: '563' }));
    await user.click(screen.getByRole('button', { name: 'Remove condition 1' }));
    expect(screen.getByLabelText('Condition 1 MARC tag')).toHaveValue('563');
    expect(screen.getByRole('button', { name: 'Remove condition 1' })).toBeDisabled();
  });

  it('compacts later slots when an earlier MARC tag is cleared', async () => {
    const user = userEvent.setup();
    renderPanel(values({ c2_marcTag: '563' }));

    await user.clear(screen.getByLabelText('Condition 1 MARC tag'));
    expect(screen.getByLabelText('Condition 1 MARC tag')).toHaveValue('563');
    expect(screen.queryByRole('heading', { name: 'Condition 2' })).not.toBeInTheDocument();
  });

  it('switches AND/OR and describes the active search in plain language', async () => {
    const user = userEvent.setup();
    renderPanel(values({
      c2_marcTag: '563', c2_occurrenceCondition: 'missing',
      c2_firstIndicator: 'any', c2_secondIndicator: 'any', c2_subfieldCode: 'a',
      c2_contentRule: 'any', c2_caseExact: 'false',
    }));

    expect(screen.getByLabelText('MARC combination interpretation')).toHaveTextContent(
      'Records in 1 location that have 655 7? $a AND are missing 563 $a',
    );
    await user.selectOptions(screen.getByLabelText('Combine conditions'), 'any');
    expect(screen.getByLabelText('MARC combination interpretation')).toHaveTextContent(
      'Records in 1 location that have 655 7? $a OR are missing 563 $a',
    );
  });

  it.each([
    ['blank / 7', { c1_firstIndicator: 'blank', c1_secondIndicator: 'char:7' }, '655 #7 $a'],
    ['any / 7', { c1_firstIndicator: 'any', c1_secondIndicator: 'char:7' }, '655 ?7 $a'],
    ['7 / any', { c1_firstIndicator: 'char:7', c1_secondIndicator: 'any' }, '655 7? $a'],
    ['1 / blank', { c1_marcTag: '245', c1_firstIndicator: 'char:1', c1_secondIndicator: 'blank', c1_subfieldCode: '' }, '245 1#'],
    ['literal ? / any', { c1_firstIndicator: 'char:?', c1_secondIndicator: 'any' }, '655 ?? $a'],
    ['any / literal ?', { c1_firstIndicator: 'any', c1_secondIndicator: 'char:?' }, '655 ?? $a'],
    ['any / any', { c1_firstIndicator: 'any', c1_secondIndicator: 'any' }, '655 $a'],
  ])('formats indicator positions in the summary for %s', (_name, overrides, expected) => {
    renderPanel(values(overrides));
    expect(screen.getByLabelText('MARC combination interpretation')).toHaveTextContent(
      'Records in 1 location that have ' + expected,
    );
  });

  it('shows cN_ client and server field errors beside the matching active slot', () => {
    renderPanel(values({ c2_marcTag: '000' }), { c2_marcTag: 'Server rejected condition 2.' });
    const condition = screen.getByRole('group', { name: 'Condition 2' });
    expect(within(condition).getByText(/MARC tag must be exactly three digits from 001 through 999/)).toBeInTheDocument();
    expect(within(condition).getByText('Server rejected condition 2.')).toBeInTheDocument();
  });

  it('leaves inactive slot fields empty instead of injecting defaults', () => {
    renderPanel(values());
    expect(screen.queryByLabelText('Condition 2 MARC tag')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Condition 1 MARC tag')).toHaveValue('655');
  });
});
