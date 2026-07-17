import { render, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import JoinPanel from './JoinPanel';

const findPath = vi.hoisted(() => vi.fn());

vi.mock('../api/client', () => ({ findPath }));

describe('JoinPanel Builder identity', () => {
  it('requests canonical paths and preserves catalog relationship IDs', async () => {
    const onCustomJoinsChange = vi.fn();
    findPath.mockResolvedValue({
      path: {
        chain: ['inventory.item__t', 'inventory.location__t'],
        hops: 1,
        joins: [{
          from_table: 'inventory.item__t',
          from_column: 'effective_location_id',
          to_table: 'inventory.location__t',
          to_column: 'id',
          foreign_key: 'item_effective_location_fk',
          relationship_id: 'item-effective-location',
          pair_id: 'item-location',
        }],
        sql_fragment: '',
      },
    });

    render(
      <JoinPanel
        schemaIdentity="ldlite"
        selectedTables={['inventory.item__t', 'inventory.location__t']}
        tableDetails={{}}
        joinMode="auto"
        customJoins={[]}
        onJoinModeChange={vi.fn()}
        onCustomJoinsChange={onCustomJoinsChange}
      />,
    );

    await waitFor(() => expect(findPath).toHaveBeenCalledWith(
      'inventory.item__t',
      'inventory.location__t',
      false,
      6,
      'ldlite',
    ));
    await waitFor(() => expect(onCustomJoinsChange).toHaveBeenCalledWith([
      expect.objectContaining({
        relationship_id: 'item-effective-location',
        pair_id: 'item-location',
      }),
    ]));
  });
});
