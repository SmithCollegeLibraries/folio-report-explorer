import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ToastProvider, useToast } from './ToastProvider';

function TriggerToast() {
  const toast = useToast();

  return (
    <button onClick={() => toast.success('Feedback saved')}>
      Save feedback
    </button>
  );
}

describe('ToastProvider', () => {
  it('shows success toasts from child components', () => {
    render(
      <ToastProvider>
        <TriggerToast />
      </ToastProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Save feedback' }));

    expect(screen.getByRole('status')).toHaveTextContent('Feedback saved');
  });
});
