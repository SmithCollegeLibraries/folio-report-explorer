import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ParamInput from './ParamInput';

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
});
