import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ParamInput from './ParamInput';

afterEach(cleanup);

describe('ParamInput', () => {
  it('applies MARC tag input hints to its labelled text input', () => {
    render(
      <ParamInput
        param={{
          name: 'marcTag',
          type: 'text',
          label: 'MARC tag',
          required: true,
          default: '',
          resolvedDefault: '',
          input_mode: 'numeric',
          pattern: '[0-9]{3}',
          max_length: 3,
        }}
        value=""
        onChange={vi.fn()}
      />,
    );

    const input = screen.getByLabelText(/MARC tag/i);
    expect(input).toHaveAttribute('inputmode', 'numeric');
    expect(input).toHaveAttribute('pattern', '[0-9]{3}');
    expect(input).toHaveAttribute('maxlength', '3');
  });

  it.each([
    {
      label: 'Location',
      param: {
        name: 'locationId',
        type: 'select' as const,
        label: 'Location',
        required: true,
        default: '',
        resolvedDefault: '',
      },
      options: [{ value: 'main', label: 'Main library' }],
    },
    {
      label: 'Record IDs',
      param: {
        name: 'recordIds',
        type: 'list' as const,
        label: 'Record IDs',
        required: false,
        default: '',
        resolvedDefault: '',
      },
    },
  ])('associates the $label label with its control', ({ label, param, options }) => {
    render(
      <ParamInput
        param={param}
        value=""
        options={options}
        onChange={vi.fn()}
      />,
    );

    expect(screen.getByLabelText(new RegExp(label, 'i'))).toBeInTheDocument();
  });

  it('searches and selects multiple report options while preserving UUID values', async () => {
    const user = userEvent.setup();
    const smithInternetId = '67204874-e4d7-495b-9247-62cd27d9ea31';
    const smithDvdId = 'c740efa7-7905-4514-995f-b6b23cc5e4b8';

    function Harness() {
      const [value, setValue] = useState('');
      return (
        <ParamInput
          param={{
            name: 'locationIds',
            type: 'multiselect' as never,
            label: 'Locations',
            required: true,
            default: '',
            resolvedDefault: '',
            placeholder: 'Search locations',
            max_selections: 100,
          } as never}
          value={value}
          options={[
            {
              value: smithInternetId,
              label: 'Smith College — SC Neilson Library — SC Internet [SCINT]',
            },
            {
              value: smithDvdId,
              label: 'Smith College — SC Neilson Library — SC Neilson DVD [SNDVD]',
            },
          ]}
          onChange={setValue}
        />
      );
    }

    render(<Harness />);
    await user.click(screen.getByRole('button', { name: /select locations/i }));
    const search = screen.getByRole('searchbox', { name: /search locations/i });
    await user.type(search, 'scint');

    expect(screen.getByRole('checkbox', { name: /sc internet/i })).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: /neilson dvd/i })).not.toBeInTheDocument();

    await user.click(screen.getByRole('checkbox', { name: /sc internet/i }));
    await user.clear(search);
    await user.click(screen.getByRole('checkbox', { name: /neilson dvd/i }));

    expect(screen.getByRole('button', { name: '2 locations selected' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /remove .*sc internet/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /remove .*sc neilson dvd/i })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /remove .*sc internet/i }));
    expect(screen.getByRole('button', { name: '1 location selected' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /remove .*sc internet/i })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /clear all locations/i }));
    expect(screen.getByText('No locations selected')).toBeInTheDocument();
  });

  it('shows an empty search state and enforces the configured selection limit', async () => {
    const user = userEvent.setup();
    const options = [
      { value: '11111111-1111-4111-8111-111111111111', label: 'Main Library [MAIN]' },
      { value: '22222222-2222-4222-8222-222222222222', label: 'Science Library [SCI]' },
    ];

    function Harness() {
      const [value, setValue] = useState('');
      return (
        <ParamInput
          param={{
            name: 'locationIds',
            type: 'multiselect' as never,
            label: 'Locations',
            required: true,
            default: '',
            resolvedDefault: '',
            max_selections: 1,
          } as never}
          value={value}
          options={options}
          onChange={setValue}
        />
      );
    }

    render(<Harness />);
    await user.click(screen.getByRole('button', { name: /select locations/i }));
    const listbox = screen.getByRole('listbox', { name: /location options/i });
    await user.click(within(listbox).getByRole('checkbox', { name: /main library/i }));

    expect(within(listbox).getByRole('checkbox', { name: /science library/i })).toBeDisabled();
    expect(screen.getByText('Maximum of 1 location selected.')).toBeInTheDocument();

    const search = screen.getByRole('searchbox', { name: /search locations/i });
    await user.clear(search);
    await user.type(search, 'does not exist');
    expect(screen.getByText('No matching locations.')).toBeInTheDocument();
  });
});
