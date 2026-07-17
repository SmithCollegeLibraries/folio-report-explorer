import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CanonicalRelationship } from '../types';
import JoinPanel from './JoinPanel';
import type { RelationshipGroups } from './builderRelationships';

const findPath = vi.hoisted(() => vi.fn());

vi.mock('../api/client', () => ({ findPath }));

const pairId = 'inventory.item__t<->inventory.location__t';
const effectiveId = 'inventory.item__t.effective_location_id->inventory.location__t.id';
const permanentId = 'inventory.item__t.permanent_location_id->inventory.location__t.id';
const temporaryId = 'inventory.item__t.temporary_location_id->inventory.location__t.id';

function relationship(
  relationshipId: string,
  fromColumn: string,
  isDefault = false,
): CanonicalRelationship {
  return {
    from_table: 'inventory.item__t',
    from_column: fromColumn,
    to_table: 'inventory.location__t',
    to_column: 'id',
    parent_table: 'inventory.location__t',
    parent_column: 'id',
    local_column: fromColumn,
    foreign_key: `item_${fromColumn}_fk`,
    relationship_id: relationshipId,
    pair_id: pairId,
    label: fromColumn,
    is_default: isDefault,
    source: 'overlay',
  } as CanonicalRelationship;
}

const effective = relationship(effectiveId, 'effective_location_id', true);
const permanent = relationship(permanentId, 'permanent_location_id');
const temporary = relationship(temporaryId, 'temporary_location_id');
const relationshipGroups: RelationshipGroups = {
  [pairId]: {
    pairId,
    leftTable: 'inventory.item__t',
    rightTable: 'inventory.location__t',
    defaultRelationshipId: effectiveId,
    relationships: [effective, permanent, temporary],
  },
};

describe('JoinPanel relationship alternatives', () => {
  afterEach(cleanup);

  beforeEach(() => {
    findPath.mockReset();
    findPath.mockResolvedValue({
      path: {
        chain: ['inventory.item__t', 'inventory.location__t'],
        hops: 1,
        joins: [{
          from_table: 'inventory.item__t',
          from_column: 'effective_location_id',
          to_table: 'inventory.location__t',
          to_column: 'id',
          foreign_key: 'item_effective_location_id_fk',
          relationship_id: effectiveId,
          pair_id: pairId,
        }],
        sql_fragment: '',
      },
    });
  });

  it('selects and resets a direct relationship while preserving join type', async () => {
    const user = userEvent.setup();
    const onRelationshipChange = vi.fn();
    const onResetRelationships = vi.fn();
    const onCustomJoinsChange = vi.fn();
    const commonProps = {
      selectedTables: ['inventory.item__t', 'inventory.location__t'],
      joinMode: 'manual' as const,
      customJoins: [{
        from_table: 'inventory.item__t',
        from_column: 'effective_location_id',
        to_table: 'inventory.location__t',
        to_column: 'id',
        foreign_key: 'item_effective_location_id_fk',
        relationship_id: effectiveId,
        pair_id: pairId,
        join_type: 'LEFT JOIN' as const,
      }],
      relationshipGroups,
      onRelationshipChange,
      onResetRelationships,
      onJoinModeChange: vi.fn(),
      onCustomJoinsChange,
      onDefaultJoinsChange: vi.fn(),
    };

    const { rerender } = render(
      <JoinPanel {...commonProps} activeRelationshipOverrides={{}} />,
    );

    expect(await screen.findByTestId(`join-from-column-${pairId}`))
      .toHaveTextContent('effective_location_id');
    const selector = screen.getByRole('combobox', {
      name: 'Relationship for inventory.item__t and inventory.location__t',
    });
    expect(selector).toHaveValue(effectiveId);
    expect(screen.getByRole('option', { name: 'effective_location_id — Default' }))
      .toBeInTheDocument();

    await user.selectOptions(selector, permanentId);
    expect(onRelationshipChange).toHaveBeenCalledWith(pairId, permanentId);
    expect(onCustomJoinsChange).not.toHaveBeenCalled();

    rerender(
      <JoinPanel
        {...commonProps}
        activeRelationshipOverrides={{ [pairId]: permanentId }}
      />,
    );

    expect(await screen.findByTestId(`join-from-column-${pairId}`))
      .toHaveTextContent('permanent_location_id');
    expect(screen.getByTestId(`join-to-column-${pairId}`)).toHaveTextContent('id');
    expect(screen.getByRole('combobox', {
      name: 'Relationship for inventory.item__t and inventory.location__t',
    })).toHaveValue(permanentId);
    expect(screen.getByRole('combobox', { name: 'Join type for inventory.item__t and inventory.location__t' }))
      .toHaveValue('LEFT JOIN');

    await user.click(screen.getByRole('button', { name: 'Reset to auto' }));
    expect(onResetRelationships).toHaveBeenCalledTimes(1);
    expect(commonProps.onJoinModeChange).toHaveBeenCalledWith('auto');
    await waitFor(() => expect(onCustomJoinsChange).toHaveBeenCalledWith([
      expect.objectContaining({ relationship_id: effectiveId, join_type: 'JOIN' }),
    ]));

    rerender(
      <JoinPanel
        {...commonProps}
        joinMode="auto"
        customJoins={[]}
        activeRelationshipOverrides={{}}
      />,
    );
    expect(screen.getByRole('combobox', {
      name: 'Relationship for inventory.item__t and inventory.location__t',
    })).toHaveValue(effectiveId);
  });
});
