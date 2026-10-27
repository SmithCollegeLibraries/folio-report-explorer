import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlStructuredAnswerInput from './AcrlStructuredAnswerInput';

describe('AcrlStructuredAnswerInput', () => {
  afterEach(cleanup);

  it('renders check-all options and serializes selections with optional Other text', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<AcrlStructuredAnswerInput options={{ kind: 'checkbox', rows: [], choices: ['A', 'Other'], allowOther: true }} value="" disabled={false} onChange={onChange} />);

    await user.click(screen.getByRole('checkbox', { name: 'A' }));
    expect(onChange).toHaveBeenLastCalledWith('{"selected":["A"],"other":""}');
    expect(screen.getByRole('checkbox', { name: 'Other' })).toBeInTheDocument();
    expect(screen.getByLabelText('Other response')).toBeInTheDocument();
  });

  it('renders a single-choice radio list and ordered rating scale', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = render(<AcrlStructuredAnswerInput options={{ kind: 'radio', rows: [], choices: ['Yes', 'No'], allowOther: false }} value="" disabled={false} onChange={onChange} />);

    await user.click(screen.getByRole('radio', { name: 'Yes' }));
    expect(onChange).toHaveBeenLastCalledWith('{"value":"Yes"}');
    rerender(<AcrlStructuredAnswerInput options={{ kind: 'rating', rows: [], choices: ['Much higher', 'Same', 'Much lower'], allowOther: false }} value="" disabled={false} onChange={onChange} />);
    expect(screen.getByRole('group', { name: 'Rating scale' })).toBeInTheDocument();
    expect(screen.getAllByRole('radio')).toHaveLength(3);
  });

  it('renders one radio per grid row and a free-text field for an Other row', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<AcrlStructuredAnswerInput options={{ kind: 'grid', rows: ['Policy', 'Other (please specify)'], choices: ['Yes', 'No', 'Don’t know'], allowOther: true }} value="" disabled={false} onChange={onChange} />);

    expect(screen.getAllByRole('radio')).toHaveLength(3);
    await user.click(screen.getByRole('radio', { name: 'Policy: Yes' }));
    expect(onChange).toHaveBeenLastCalledWith('{"rows":{"Policy":"Yes","Other (please specify)":""}}');
    expect(screen.getByLabelText('Other (please specify)')).toBeInTheDocument();
  });

  it('renders a plain text answer for the free-text fallback', async () => {
    const user = userEvent.setup();
    function ControlledTextAnswer() {
      const [value, setValue] = useState('');
      return <AcrlStructuredAnswerInput options={{ kind: 'text', rows: [], choices: [], allowOther: false }} value={value} disabled={false} onChange={setValue} />;
    }
    render(<ControlledTextAnswer />);

    await user.type(screen.getByRole('textbox'), 'Explain');
    expect(screen.getByRole('textbox')).toHaveValue('Explain');
  });
});
