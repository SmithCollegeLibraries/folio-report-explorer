import { render, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import JoinPanel from './JoinPanel';

const findPath = vi.hoisted(() => vi.fn());

vi.mock('../api/client', () => ({ findPath }));

describe('JoinPanel Builder identity', () => {
  it('requests canonical paths and preserves catalog relationship IDs', async () => {
    const onCustomJoinsChange = vi.fn();
    const onDefaultJoinsChange = vi.fn();
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
        onDefaultJoinsChange={onDefaultJoinsChange}
      />,
    );

    await waitFor(() => expect(findPath).toHaveBeenCalledWith(
      'inventory.item__t',
      'inventory.location__t',
      false,
      6,
      'ldlite',
    ));
    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenCalledWith([
      expect.objectContaining({
        relationship_id: 'item-effective-location',
        pair_id: 'item-location',
      }),
    ]));
    expect(onCustomJoinsChange).not.toHaveBeenCalled();
  });

  it('replaces and clears the default path independently of custom joins', async () => {
    const onCustomJoinsChange = vi.fn();
    const onDefaultJoinsChange = vi.fn();
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

    const { rerender } = render(
      <JoinPanel
        schemaIdentity="ldlite"
        selectedTables={['inventory.item__t', 'inventory.location__t']}
        tableDetails={{}}
        joinMode="manual"
        customJoins={[{
          from_table: 'inventory.item__t',
          from_column: 'effective_location_id',
          to_table: 'inventory.location__t',
          to_column: 'id',
          foreign_key: 'item_effective_location_fk',
          relationship_id: 'item-effective-location',
          pair_id: 'item-location',
          join_type: 'LEFT JOIN',
        }]}
        onJoinModeChange={vi.fn()}
        onCustomJoinsChange={onCustomJoinsChange}
        onDefaultJoinsChange={onDefaultJoinsChange}
      />,
    );

    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([
      expect.objectContaining({ relationship_id: 'item-effective-location' }),
    ]));
    expect(onCustomJoinsChange).not.toHaveBeenCalled();

    onDefaultJoinsChange.mockClear();
    findPath.mockResolvedValue({ path: null });
    rerender(
      <JoinPanel
        schemaIdentity="ldlite"
        selectedTables={['inventory.item__t', 'inventory.holdings_record__t']}
        tableDetails={{}}
        joinMode="manual"
        customJoins={[]}
        onJoinModeChange={vi.fn()}
        onCustomJoinsChange={onCustomJoinsChange}
        onDefaultJoinsChange={onDefaultJoinsChange}
      />,
    );

    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([]));

    onDefaultJoinsChange.mockClear();
    rerender(
      <JoinPanel
        schemaIdentity="ldlite"
        selectedTables={['inventory.item__t']}
        tableDetails={{}}
        joinMode="manual"
        customJoins={[]}
        onJoinModeChange={vi.fn()}
        onCustomJoinsChange={onCustomJoinsChange}
        onDefaultJoinsChange={onDefaultJoinsChange}
      />,
    );

    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([]));
  });
});
