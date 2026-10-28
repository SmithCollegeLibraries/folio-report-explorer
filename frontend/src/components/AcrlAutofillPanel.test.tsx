import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AcrlAutofillPanel from './AcrlAutofillPanel';

const { runAutofill } = vi.hoisted(() => ({ runAutofill: vi.fn() }));
vi.mock('../api/acrl', () => ({ runAcrlAutofill: runAutofill }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function datedResult(value: number, queryId = 'acrl-81') {
  return {
    value,
    queryId,
    queryVersion: 1,
    ranAt: '2026-10-28T12:00:00-04:00',
    asOf: null,
    fyStart: '2024-07-01',
    fyEnd: '2025-07-01',
    kind: 'dated' as const,
  };
}

describe('ACRL FOLIO auto-fill panel', () => {
  it('adds typed ILLiad counts to the FOLIO suggestion and appends provenance to the existing note', async () => {
    runAutofill.mockResolvedValue(datedResult(6017));
    const onUseValue = vi.fn();
    render(<AcrlAutofillPanel year={2025} sheet="survey" questionId="81" part="" currentValue={null} currentNote="Checked last year" disabled={false} onUseValue={onUseValue} />);

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await screen.findByText(/Smith-owned physical loans checked out at a non-Smith service point/)).toBeInTheDocument();
    expect(screen.getByText('Enter a number for each outside-FOLIO count; enter 0 when there is none.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Use this value' })).toBeDisabled();
    await userEvent.type(screen.getByRole('spinbutton', { name: 'ILLiad returnables' }), '263');
    await userEvent.type(screen.getByRole('spinbutton', { name: 'ILLiad non-returnables' }), '824');
    expect(screen.getByText('Total: 7,104')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Use this value' }));
    expect(onUseValue).toHaveBeenCalledWith(
      '7104',
      'Checked last year\nFOLIO consortial lending 6,017 (query acrl-81 v1, FY2025, run 2026-10-28) + ILLiad returnables 263 + ILLiad non-returnables 824',
    );
    expect(runAutofill).toHaveBeenCalledWith(2025, 'survey', '81', '');
  });

  it('adds an EDS count to digital titles before accepting the total', async () => {
    runAutofill.mockResolvedValue(datedResult(2100, 'acrl-40B'));
    const onUseValue = vi.fn();
    render(<AcrlAutofillPanel year={2025} sheet="survey" questionId="40" part="B" currentValue={null} currentNote="" disabled={false} onUseValue={onUseValue} />);

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    await userEvent.type(screen.getByRole('spinbutton', { name: /EDS custom catalog/ }), '50');
    expect(screen.getByText('Total: 2,150')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Use this value' }));
    expect(onUseValue).toHaveBeenCalledWith(
      '2150',
      'FOLIO digital book titles 2,100 (query acrl-40B v1, FY2025, run 2026-10-28) + EDS custom catalog (minus DDA packages) 50',
    );
  });

  it('requires explicit confirmation before replacing an existing answer', async () => {
    runAutofill.mockResolvedValue(datedResult(6017));
    const onUseValue = vi.fn();
    render(<AcrlAutofillPanel year={2025} sheet="survey" questionId="81" part="" currentValue="7000" currentNote="" disabled={false} onUseValue={onUseValue} />);
    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));

    const panel = await screen.findByRole('region', { name: 'FOLIO suggestion' });
    expect(within(panel).getByText('Current value: 7,000')).toBeInTheDocument();
    await userEvent.type(within(panel).getByRole('spinbutton', { name: 'ILLiad returnables' }), '0');
    await userEvent.type(within(panel).getByRole('spinbutton', { name: 'ILLiad non-returnables' }), '0');
    const useButton = within(panel).getByRole('button', { name: 'Use this value' });
    expect(useButton).toBeDisabled();
    await userEvent.click(within(panel).getByRole('checkbox', { name: 'I confirm replacing the current answer' }));
    expect(useButton).toBeEnabled();
    await userEvent.click(useButton);
    expect(onUseValue).toHaveBeenCalledTimes(1);
  });

  it('warns when a snapshot is more than 30 days from the fiscal year end', async () => {
    runAutofill.mockResolvedValue({
      value: 51600,
      queryId: 'acrl-43A',
      queryVersion: 1,
      ranAt: '2026-10-28T12:00:00-04:00',
      asOf: '2026-10-28',
      fyStart: null,
      fyEnd: null,
      kind: 'snapshot' as const,
    });
    render(<AcrlAutofillPanel year={2026} sheet="survey" questionId="43" part="A" currentValue={null} currentNote="" disabled={false} onUseValue={vi.fn()} />);
    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    const panel = await screen.findByRole('region', { name: 'FOLIO suggestion' });
    expect(within(panel).getByText('This counts FOLIO today, not on June 30.')).toBeInTheDocument();
    expect(within(panel).getByText('As of October 28, 2026')).toBeInTheDocument();
  });

  it('hides auto-fill for questions that stay manual', () => {
    render(<AcrlAutofillPanel year={2026} sheet="survey" questionId="43" part="B" currentValue={null} currentNote="" disabled={false} onUseValue={vi.fn()} />);
    expect(screen.queryByRole('button', { name: 'Fill from FOLIO' })).not.toBeInTheDocument();
  });

  it('shows the query failure without creating an answer', async () => {
    runAutofill.mockRejectedValue(new Error('offline'));
    const onUseValue = vi.fn();
    render(<AcrlAutofillPanel year={2026} sheet="survey" questionId="60" part="" currentValue={null} currentNote="" disabled={false} onUseValue={onUseValue} />);
    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Could not get a FOLIO value.');
    expect(onUseValue).not.toHaveBeenCalled();
  });

  it('shows the server reason when a query is already running', async () => {
    runAutofill.mockRejectedValue({
      response: { status: 409, data: { error: 'An auto-fill query is already running for this question.' } },
    });
    render(<AcrlAutofillPanel year={2026} sheet="survey" questionId="60" part="" currentValue={null} currentNote="" disabled={false} onUseValue={vi.fn()} />);

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Could not get a FOLIO value. An auto-fill query is already running for this question.');
  });

  it('uses the long-running collection hint while a query is in flight', async () => {
    runAutofill.mockReturnValue(new Promise(() => undefined));
    render(<AcrlAutofillPanel year={2026} sheet="survey" questionId="60" part="" currentValue={null} currentNote="" disabled={false} onUseValue={vi.fn()} />);

    await userEvent.click(screen.getByRole('button', { name: 'Fill from FOLIO' }));
    expect(screen.getByRole('button', { name: 'Querying FOLIO… collection counts can take a minute or more.' })).toBeDisabled();
    expect(screen.getByRole('status')).toHaveTextContent('Querying FOLIO… collection counts can take a minute or more.');
  });
});
