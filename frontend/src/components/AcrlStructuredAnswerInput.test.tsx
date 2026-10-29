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
    render(<AcrlStructuredAnswerInput question="Select all" options={{ kind: 'checkbox', rows: [], choices: ['A', 'Other'], allowOther: true }} value="" disabled={false} onChange={onChange} />);

    await user.click(screen.getByRole('checkbox', { name: 'A' }));
    expect(onChange).toHaveBeenLastCalledWith('{"selected":["A"],"other":""}');
    expect(screen.getByRole('checkbox', { name: 'Other' })).toBeInTheDocument();
    expect(screen.getByLabelText('Other response')).toBeInTheDocument();
  });

  it('renders radio choices in order and saves the existing single-choice answer shape', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<AcrlStructuredAnswerInput question="Do you agree?" options={{ kind: 'radio', rows: [], choices: ['Yes', 'No'], allowOther: false }} value="" disabled={false} onChange={onChange} />);

    const select = screen.getByRole('combobox', { name: 'Do you agree?' });
    expect(Array.from((select as HTMLSelectElement).options).map((option) => option.textContent)).toEqual(['Choose…', 'Yes', 'No']);
    await user.selectOptions(select, 'Yes');
    expect(onChange).toHaveBeenLastCalledWith('{"value":"Yes"}');
  });

  it('clears a single-choice answer when Choose is selected again', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const options = { kind: 'rating' as const, rows: [], choices: ['Much higher', 'Higher', 'About the same'], allowOther: false };
    const { rerender } = render(<AcrlStructuredAnswerInput question="How did concerns change?" options={options} value="" disabled={false} onChange={onChange} />);
    const select = screen.getByRole('combobox', { name: 'How did concerns change?' });

    await user.selectOptions(select, 'Higher');
    expect(onChange).toHaveBeenLastCalledWith('{"value":"Higher"}');
    rerender(<AcrlStructuredAnswerInput question="How did concerns change?" options={options} value='{"value":"Higher"}' disabled={false} onChange={onChange} />);
    await user.selectOptions(select, '');
    expect(onChange).toHaveBeenLastCalledWith('{"value":""}');
  });

  it('keeps optional Other text when changing a single-choice answer', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<AcrlStructuredAnswerInput question="Select one" options={{ kind: 'radio', rows: [], choices: ['Other', 'No'], allowOther: true }} value='{"value":"Other","other":"A specific reason"}' disabled={false} onChange={onChange} />);

    expect(screen.getByLabelText('Other response')).toHaveValue('A specific reason');
    await user.selectOptions(screen.getByRole('combobox', { name: 'Select one' }), 'No');
    expect(onChange).toHaveBeenLastCalledWith('{"value":"No","other":"A specific reason"}');
  });

  it('disables the single-choice select and Other text when locked', () => {
    render(<AcrlStructuredAnswerInput question="Select one" options={{ kind: 'radio', rows: [], choices: ['Other', 'No'], allowOther: true }} value="" disabled onChange={vi.fn()} />);

    expect(screen.getByRole('combobox', { name: 'Select one' })).toBeDisabled();
    expect(screen.getByLabelText('Other response')).toBeDisabled();
  });

  it('renders an ordered select for each grid row and preserves the rows answer shape', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<AcrlStructuredAnswerInput question="Policy and funding" options={{ kind: 'grid', rows: ['Policy', 'Funding'], choices: ['Yes', 'No', 'Don’t know'], allowOther: false }} value="" disabled={false} onChange={onChange} />);

    const policySelect = screen.getByRole('combobox', { name: 'Policy' });
    const fundingSelect = screen.getByRole('combobox', { name: 'Funding' });
    expect(screen.getAllByRole('combobox')).toHaveLength(2);
    expect(Array.from((policySelect as HTMLSelectElement).options).map((option) => option.textContent)).toEqual(['Choose…', 'Yes', 'No', 'Don’t know']);
    await user.selectOptions(policySelect, 'Yes');
    expect(onChange).toHaveBeenLastCalledWith('{"rows":{"Policy":"Yes","Funding":""}}');
    expect(fundingSelect).toBeEnabled();
  });

  it('disables every grid row select when locked', () => {
    render(<AcrlStructuredAnswerInput question="Policy and funding" options={{ kind: 'grid', rows: ['Policy', 'Funding'], choices: ['Yes', 'No'], allowOther: false }} value="" disabled onChange={vi.fn()} />);

    expect(screen.getByRole('combobox', { name: 'Policy' })).toBeDisabled();
    expect(screen.getByRole('combobox', { name: 'Funding' })).toBeDisabled();
  });

  it('renders Other grid rows as text inputs and saves typed text while other rows stay dropdowns', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    function ControlledGridAnswer() {
      const [value, setValue] = useState('{"rows":{"Grants":"Yes","Other (please specify):":""}}');
      return <AcrlStructuredAnswerInput question="T5" options={{ kind: 'grid', rows: ['Grants', 'Other (please specify):'], choices: ['Yes', 'No', 'Don’t know'], allowOther: true }} value={value} disabled={false} onChange={(nextValue) => {
        onChange(nextValue);
        setValue(nextValue);
      }} />;
    }
    render(<ControlledGridAnswer />);

    expect(screen.getAllByRole('combobox')).toHaveLength(1);
    expect(screen.getByRole('combobox', { name: 'Grants' })).toHaveValue('Yes');
    const otherInput = screen.getByRole('textbox', { name: 'Other (please specify):' });
    await user.type(otherInput, 'Local response');
    expect(onChange).toHaveBeenLastCalledWith('{"rows":{"Grants":"Yes","Other (please specify):":"Local response"}}');
  });

  it.each([
    { question: 'T5', rows: ['Grants', 'Instructional materials', 'Other (please specify):'] },
    { question: 'T6', rows: ['Policy changes', 'Training', 'Other (please specify):'] },
  ])('round-trips stored $question grid choices and Other text', async ({ question, rows }) => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const options = { kind: 'grid' as const, rows, choices: ['Yes', 'No', 'Don’t know'], allowOther: true };
    const initialAnswer = JSON.stringify({ rows: { [rows[0]]: 'Yes', [rows[1]]: 'Don’t know', [rows[2]]: 'Current text' } });
    function ControlledGridAnswer() {
      const [value, setValue] = useState(initialAnswer);
      return <AcrlStructuredAnswerInput question={question} options={options} value={value} disabled={false} onChange={(nextValue) => {
        onChange(nextValue);
        setValue(nextValue);
      }} />;
    }
    render(<ControlledGridAnswer />);

    const choiceSelect = screen.getByRole('combobox', { name: rows[0] });
    const otherInput = screen.getByRole('textbox', { name: rows[2] });
    expect(choiceSelect).toHaveValue('Yes');
    expect(screen.getByRole('combobox', { name: rows[1] })).toHaveValue('Don’t know');
    expect(otherInput).toHaveValue('Current text');

    await user.selectOptions(choiceSelect, 'No');
    expect(onChange).toHaveBeenLastCalledWith(JSON.stringify({ rows: { [rows[0]]: 'No', [rows[1]]: 'Don’t know', [rows[2]]: 'Current text' } }));
    await user.clear(otherInput);
    await user.type(otherInput, 'Updated text');
    expect(onChange).toHaveBeenLastCalledWith(JSON.stringify({ rows: { [rows[0]]: 'No', [rows[1]]: 'Don’t know', [rows[2]]: 'Updated text' } }));
  });

  it('renders a plain text answer for the free-text fallback', async () => {
    const user = userEvent.setup();
    function ControlledTextAnswer() {
      const [value, setValue] = useState('');
      return <AcrlStructuredAnswerInput question="Explain" options={{ kind: 'text', rows: [], choices: [], allowOther: false }} value={value} disabled={false} onChange={setValue} />;
    }
    render(<ControlledTextAnswer />);

    await user.type(screen.getByRole('textbox'), 'Explain');
    expect(screen.getByRole('textbox')).toHaveValue('Explain');
  });
});
