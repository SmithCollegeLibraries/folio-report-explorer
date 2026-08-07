import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MarcIndicatorInput from './MarcIndicatorInput';

afterEach(cleanup);

describe('MarcIndicatorInput', () => {
  it('offers Any, Blank, digits, and a custom choice', () => {
    render(<MarcIndicatorInput name="firstIndicator" label="First indicator" value="any" onChange={vi.fn()} />);
    const select = screen.getByRole('combobox', { name: 'First indicator' });
    expect(select).toHaveValue('any');
    expect(screen.getByRole('option', { name: 'Blank (#)' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: '9' })).toHaveValue('char:9');
    expect(screen.getByRole('option', { name: 'Custom character' })).toBeInTheDocument();
  });

  it('emits a custom character and normalizes typed backslash to blank', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<MarcIndicatorInput name="secondIndicator" label="Second indicator" value="any" onChange={onChange} />);

    await user.selectOptions(screen.getByRole('combobox', { name: 'Second indicator' }), 'custom');
    expect(onChange).toHaveBeenLastCalledWith('char:X');

    const customInput = screen.getByRole('textbox', { name: 'Second indicator custom character' });
    await user.clear(customInput);
    await user.type(customInput, '\\');
    expect(onChange).toHaveBeenLastCalledWith('blank');
  });

  it('emits any when switching away from a custom value', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<MarcIndicatorInput name="firstIndicator" label="First indicator" value="char:X" onChange={onChange} />);
    await user.selectOptions(screen.getByRole('combobox', { name: 'First indicator' }), 'any');
    expect(onChange).toHaveBeenLastCalledWith('any');
  });
});

