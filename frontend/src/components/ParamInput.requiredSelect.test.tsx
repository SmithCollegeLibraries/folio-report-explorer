import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ParamInput from './ParamInput';

afterEach(cleanup);

describe('required report selects', () => {
  it('omits the Any option only for required selects', () => {
    const options = [{ value: 'one', label: 'One' }];
    const required = {
      name: 'scope', type: 'select' as const, label: 'Scope', required: true,
      default: 'one', resolvedDefault: 'one',
    };
    const optional = {
      name: 'optionalScope', type: 'select' as const, label: 'Optional scope', required: false,
      default: '', resolvedDefault: '',
    };

    render(
      <>
        <ParamInput param={required} value="one" options={options} onChange={vi.fn()} />
        <ParamInput param={optional} value="" options={options} onChange={vi.fn()} />
      </>,
    );

    expect(screen.getByLabelText(/^Scope/)).toHaveValue('one');
    expect(screen.getByLabelText(/^Scope/).querySelector('option[value=""]')).toBeNull();
    expect(screen.getByLabelText(/^Optional scope/).querySelector('option[value=""]')).toHaveTextContent('-- Any --');
  });
});
