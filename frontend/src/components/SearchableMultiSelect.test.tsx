import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import SearchableMultiSelect from './SearchableMultiSelect';
import { selectionLabels } from '../utils/multiSelectLabels';

afterEach(cleanup);

const receiptOptions = [
  { value: 'pending', label: 'Pending' },
  { value: 'awaiting', label: 'Awaiting Receipt' },
  { value: 'partial', label: 'Partially Received' },
  { value: 'received', label: 'Fully Received' },
];

function renderSelect({
  value,
  options = receiptOptions,
  label = 'Receipt status',
  placeholder,
  error,
}: {
  value: string;
  options?: Array<{ value: string; label: string }>;
  label?: string;
  placeholder?: string;
  error?: string;
}) {
  return render(
    <SearchableMultiSelect
      id="receipt-status"
      label={label}
      value={value}
      options={options}
      buttonPlaceholder={placeholder}
      required={Boolean(error)}
      error={error}
      errorId="receipt-status-error"
      onChange={() => {}}
    />,
  );
}

describe('selectionLabels', () => {
  it.each([
    ['Receipt status', 'Receipt status', 'Receipt statuses'],
    ['Payment status', 'Payment status', 'Payment statuses'],
    ['Workflow status', 'Workflow status', 'Workflow statuses'],
    ['Order type', 'Order type', 'Order types'],
    ['Funds', 'Fund', 'Funds'],
    ['Locations', 'Location', 'Locations'],
    ['Vendors', 'Vendor', 'Vendors'],
    ['Invoice status', 'Invoice status', 'Invoice statuses'],
  ])('pluralizes %s for summaries and option labels', (label, singular, plural) => {
    expect(selectionLabels(label)).toEqual({ singular, plural });
  });
});

describe('SearchableMultiSelect summary', () => {
  it('shows the all state without chips or a duplicate count line', () => {
    renderSelect({ value: 'pending,awaiting,partial,received' });

    const control = screen.getByRole('button', { name: '4 receipt statuses selected' });
    expect(control).toHaveTextContent('All receipt statuses (4)');
    expect(control).toHaveAttribute('title', 'Pending, Awaiting Receipt, Partially Received, Fully Received');
    expect(screen.queryByRole('button', { name: /^Remove / })).not.toBeInTheDocument();
    expect(screen.queryByText('4 receipt statuses selected')).not.toBeInTheDocument();
  });

  it('shows short selected names in the control and preserves their full title', () => {
    renderSelect({ value: 'pending,received' });

    const control = screen.getByRole('button', { name: '2 receipt statuses selected' });
    expect(control).toHaveTextContent('Pending, Fully Received');
    expect(control).toHaveAttribute('title', 'Pending, Fully Received');
    expect(screen.getAllByRole('button', { name: /^Remove / })).toHaveLength(2);
  });

  it('summarizes long selections, caps chips at three, and opens the list from more', async () => {
    const user = userEvent.setup();
    const options = Array.from({ length: 7 }, (_, index) => ({
      value: `value-${index + 1}`,
      label: `Receipt option with a long name ${index + 1}`,
    }));
    renderSelect({ value: 'value-1,value-2,value-3,value-4,value-5', options });

    const control = screen.getByRole('button', { name: '5 receipt statuses selected' });
    expect(control).toHaveTextContent('5 of 7 receipt statuses');
    expect(control).toHaveAttribute('title', options.slice(0, 5).map(({ label }) => label).join(', '));
    expect(screen.getAllByRole('button', { name: /^Remove / })).toHaveLength(3);

    await user.click(screen.getByRole('button', { name: '+2 more' }));
    expect(control).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('listbox', { name: 'Receipt status options' })).toBeInTheDocument();
  });

  it('shows the placeholder and required error when nothing is selected', () => {
    renderSelect({ value: '', placeholder: 'Select receipt statuses', error: 'Choose at least one status.' });

    const control = screen.getByRole('button', { name: 'Select receipt statuses' });
    expect(control).toHaveTextContent('Select receipt statuses');
    expect(control).toHaveAttribute('aria-required', 'true');
    expect(control).toHaveAttribute('aria-invalid', 'true');
    expect(control).toHaveAttribute('aria-describedby', 'receipt-status-error');
    expect(screen.getByRole('alert')).toHaveTextContent('Choose at least one status.');
    expect(screen.queryByRole('button', { name: /^Remove / })).not.toBeInTheDocument();
  });

  it('keeps select all and clear all in the list and updates the selected state', async () => {
    const user = userEvent.setup();
    const options = [
      { value: 'one', label: 'Main Library' },
      { value: 'two', label: 'Science Library' },
    ];
    function Harness() {
      const [value, setValue] = useState('');
      return (
        <SearchableMultiSelect
          id="locations"
          label="Locations"
          value={value}
          options={options}
          onChange={setValue}
        />
      );
    }
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Select locations' }));
    await user.click(screen.getByRole('button', { name: 'Select all locations' }));
    const allControl = screen.getByRole('button', { name: '2 locations selected' });
    expect(allControl).toHaveTextContent('All locations (2)');
    expect(screen.queryByRole('button', { name: /^Remove / })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Clear all locations' }));
    expect(screen.getByRole('button', { name: 'Select locations' })).toBeInTheDocument();
  });

  it('does not let select all exceed the configured selection limit', async () => {
    const user = userEvent.setup();
    render(
      <SearchableMultiSelect
        id="locations"
        label="Locations"
        value=""
        options={[
          { value: 'one', label: 'Main Library' },
          { value: 'two', label: 'Science Library' },
        ]}
        maxSelections={1}
        onChange={() => {}}
      />,
    );

    await user.click(screen.getByRole('button', { name: 'Select locations' }));
    expect(screen.getByRole('button', { name: 'Select all locations' })).toBeDisabled();
  });

  it('keeps the listbox, search, option, and escape keyboard labels available', async () => {
    const user = userEvent.setup();
    renderSelect({ value: 'pending' });
    const control = screen.getByRole('button', { name: '1 receipt status selected' });

    await user.click(control);
    expect(control).toHaveAttribute('aria-haspopup', 'listbox');
    expect(control).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('searchbox', { name: 'Search receipt status' })).toBeInTheDocument();
    expect(screen.getByRole('listbox', { name: 'Receipt status options' })).toHaveAttribute('aria-multiselectable', 'true');
    expect(screen.getByRole('checkbox', { name: 'Pending' })).toBeChecked();
    expect(screen.getByRole('button', { name: 'Remove Pending' })).toBeInTheDocument();

    await user.keyboard('{Escape}');
    expect(control).toHaveAttribute('aria-expanded', 'false');
  });
});
