import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import ReportHelp from './ReportHelp';

afterEach(() => {
  cleanup();
  document.body.replaceChildren();
});

function renderInAppRoot() {
  const appRoot = document.createElement('div');
  appRoot.id = 'root';

  const backgroundButton = document.createElement('button');
  backgroundButton.textContent = 'Background action';
  appRoot.appendChild(backgroundButton);

  const container = document.createElement('div');
  appRoot.appendChild(container);
  document.body.appendChild(appRoot);

  const outsideButton = document.createElement('button');
  outsideButton.textContent = 'Outside root action';
  document.body.appendChild(outsideButton);

  const rendered = render(
    <ReportHelp
      reportName="Budget Year Fund Report"
      helpText="Available Budget includes calculated current encumbrances."
    />,
    { container },
  );

  return { appRoot, backgroundButton, outsideButton, ...rendered };
}

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

  it('wraps Tab from the last dialog control to the first', async () => {
    const user = userEvent.setup();
    renderInAppRoot();

    await user.click(screen.getByRole('button', { name: /how to read this report/i }));

    const iconClose = screen.getByRole('button', { name: /close report help/i });
    const footerClose = screen.getByRole('button', { name: /^close$/i });
    footerClose.focus();

    await user.tab();

    expect(iconClose).toHaveFocus();
  });

  it('wraps Shift+Tab from the first dialog control to the last', async () => {
    const user = userEvent.setup();
    renderInAppRoot();

    await user.click(screen.getByRole('button', { name: /how to read this report/i }));

    const iconClose = screen.getByRole('button', { name: /close report help/i });
    const footerClose = screen.getByRole('button', { name: /^close$/i });
    iconClose.focus();

    await user.tab({ shift: true });

    expect(footerClose).toHaveFocus();
  });

  it('inerts the application root and restores it after icon-button close', async () => {
    const user = userEvent.setup();
    const { appRoot } = renderInAppRoot();

    const trigger = screen.getByRole('button', { name: /how to read this report/i });
    await user.click(trigger);

    expect(appRoot).toHaveAttribute('inert');
    expect(appRoot).toHaveAttribute('aria-hidden', 'true');
    expect(screen.getByRole('dialog').parentElement?.parentElement).toBe(document.body);

    await user.click(screen.getByRole('button', { name: /close report help/i }));

    expect(appRoot).not.toHaveAttribute('inert');
    expect(appRoot).not.toHaveAttribute('aria-hidden');
    expect(trigger).toHaveFocus();
  });

  it('restores prior application root state when unmounted while open', () => {
    const { appRoot, unmount } = renderInAppRoot();
    const trigger = screen.getByRole('button', { name: /how to read this report/i });
    appRoot.setAttribute('inert', '');
    appRoot.setAttribute('aria-hidden', 'false');

    fireEvent.click(trigger);
    expect(appRoot).toHaveAttribute('aria-hidden', 'true');

    unmount();

    expect(appRoot).toHaveAttribute('inert');
    expect(appRoot).toHaveAttribute('aria-hidden', 'false');
  });
});
