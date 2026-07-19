import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import StatusBadge from './StatusBadge';

afterEach(cleanup);

describe('StatusBadge cancellation progress', () => {
  it('shows an animated cancelling state before cancellation is terminal', () => {
    render(<StatusBadge status="cancelling" />);

    expect(screen.getByText('Cancelling…')).toBeInTheDocument();
    expect(screen.getByText('Cancelling…').parentElement?.querySelector('svg')).toHaveClass('animate-spin');
  });
});
