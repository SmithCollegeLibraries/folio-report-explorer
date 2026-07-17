import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import JoinPanel from './JoinPanel';

const discoveredJoin = {
  from_table: 'inventory.item__t',
  from_column: 'effective_location_id',
  to_table: 'inventory.location__t',
  to_column: 'id',
  foreign_key: 'item_effective_location_fk',
  relationship_id: 'item-effective-location',
  pair_id: 'item-location',
  join_type: 'JOIN' as const,
};

const baseProps = {
  selectedTables: ['inventory.item__t', 'inventory.location__t'],
  relationshipGroups: {},
  activeRelationshipOverrides: {},
  onRelationshipChange: vi.fn(),
  onResetRelationships: vi.fn(),
  onJoinModeChange: vi.fn(),
  onCustomJoinsChange: vi.fn(),
  defaultJoins: [discoveredJoin],
  discoveryLoading: false,
  discoveryError: null,
};

describe('JoinPanel Builder identity', () => {
  afterEach(cleanup);

  it('renders the complete canonical default path supplied by Builder', () => {
    render(<JoinPanel {...baseProps} joinMode="auto" customJoins={[]} />);
    expect(screen.getByText('inventory.item__t')).toBeInTheDocument();
    expect(screen.getByText('inventory.location__t')).toBeInTheDocument();
    expect(screen.getByTestId('join-from-column-item-location')).toHaveTextContent('effective_location_id');
  });

  it('seeds manual joins from the supplied complete default path', () => {
    const onJoinModeChange = vi.fn();
    const onCustomJoinsChange = vi.fn();
    render(
      <JoinPanel
        {...baseProps}
        joinMode="auto"
        customJoins={[]}
        onJoinModeChange={onJoinModeChange}
        onCustomJoinsChange={onCustomJoinsChange}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Auto Joins' }));
    expect(onCustomJoinsChange).toHaveBeenCalledWith([
      expect.objectContaining({ relationship_id: 'item-effective-location', join_type: 'JOIN' }),
    ]);
    expect(onJoinModeChange).toHaveBeenCalledWith('manual');
  });

  it('shows discovery progress and errors without publishing partial joins', () => {
    const { rerender } = render(
      <JoinPanel {...baseProps} defaultJoins={[]} discoveryLoading joinMode="auto" customJoins={[]} />,
    );
    expect(screen.getByText('Discovering join paths…')).toBeInTheDocument();
    expect(screen.queryByTestId('join-from-column-item-location')).not.toBeInTheDocument();

    rerender(
      <JoinPanel
        {...baseProps}
        defaultJoins={[]}
        discoveryLoading={false}
        discoveryError={'Cannot find FK path to "inventory.location__t"'}
        joinMode="auto"
        customJoins={[]}
      />,
    );
    expect(screen.getByText('Cannot find FK path to "inventory.location__t"')).toBeInTheDocument();
    expect(screen.queryByTestId('join-from-column-item-location')).not.toBeInTheDocument();
  });

  it('preserves a manual join type while consuming Builder-owned defaults', () => {
    render(
      <JoinPanel
        {...baseProps}
        joinMode="manual"
        customJoins={[{ ...discoveredJoin, join_type: 'LEFT JOIN' }]}
      />,
    );
    expect(screen.getByRole('combobox', { name: /Join type/ })).toHaveValue('LEFT JOIN');
  });
});
