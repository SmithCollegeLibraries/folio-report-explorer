import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SchemaAssistant from './SchemaAssistant';

vi.mock('../api/client', () => ({ askSchema: vi.fn() }));

const originalScrollIntoView = HTMLElement.prototype.scrollIntoView;

beforeEach(() => {
  HTMLElement.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
  cleanup();
  HTMLElement.prototype.scrollIntoView = originalScrollIntoView;
});

describe('SchemaAssistant dismissal', () => {
  it('closes the floating assistant after an outside click', async () => {
    const user = userEvent.setup();
    render(
      <>
        <SchemaAssistant selectedTable={null} onNavigateTable={vi.fn()} />
        <button type="button">Outside action</button>
      </>,
    );

    await user.click(screen.getByTitle('Ask about the schema'));
    expect(screen.getByText('Schema Assistant')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Outside action' }));

    expect(screen.queryByText('Schema Assistant')).not.toBeInTheDocument();
  });
});
