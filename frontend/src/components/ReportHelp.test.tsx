import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import ReportHelp from './ReportHelp';

afterEach(cleanup);

describe('ReportHelp', () => {
  it('opens an accessible dialog and closes it with Escape', async () => {
    const user = userEvent.setup();
    const helpText =
      'Available Budget includes calculated current encumbrances.\n\nUse the fiscal year filter to change the reporting period.';

    render(<ReportHelp reportName="Budget Year Fund Report" helpText={helpText} />);

    const trigger = screen.getByRole('button', { name: /how to read this report/i });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    await user.click(trigger);

    expect(
      screen.getByRole('dialog', { name: /budget year fund report help/i }),
    ).toBeInTheDocument();
    expect(screen.getByText(/calculated current encumbrances/i)).toHaveClass(
      'whitespace-pre-line',
    );

    await user.keyboard('{Escape}');

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it('closes from the backdrop and restores focus to the trigger', async () => {
    const user = userEvent.setup();

    render(
      <ReportHelp
        reportName="Budget Year Fund Report"
        helpText="Available Budget includes calculated current encumbrances."
      />,
    );

    const trigger = screen.getByRole('button', { name: /how to read this report/i });
    await user.click(trigger);

    const backdrop = screen.getByRole('dialog').parentElement;
    expect(backdrop).not.toBeNull();
    fireEvent.mouseDown(backdrop!);

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it('closes from the visible Close button and restores focus to the trigger', async () => {
    const user = userEvent.setup();

    render(
      <ReportHelp
        reportName="Budget Year Fund Report"
        helpText="Available Budget includes calculated current encumbrances."
      />,
    );

    const trigger = screen.getByRole('button', { name: /how to read this report/i });
    await user.click(trigger);
    await user.click(screen.getByRole('button', { name: /^close$/i }));

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });
});
