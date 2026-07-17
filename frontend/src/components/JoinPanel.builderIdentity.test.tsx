import { act, cleanup, fireEvent, render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import JoinPanel from './JoinPanel';

const findPath = vi.hoisted(() => vi.fn());

vi.mock('../api/client', () => ({ findPath }));

describe('JoinPanel Builder identity', () => {
  afterEach(cleanup);

  beforeEach(() => {
    findPath.mockReset();
  });

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

  it('seeds manual joins from the complete discovered path when auto mode is toggled', async () => {
    const onJoinModeChange = vi.fn();
    const onCustomJoinsChange = vi.fn();
    const onDefaultJoinsChange = vi.fn();
    const discoveredJoin = {
      from_table: 'inventory.item__t',
      from_column: 'effective_location_id',
      to_table: 'inventory.location__t',
      to_column: 'id',
      foreign_key: 'item_effective_location_fk',
      relationship_id: 'item-effective-location',
      pair_id: 'item-location',
    };
    findPath.mockResolvedValue({
      path: {
        chain: ['inventory.item__t', 'inventory.location__t'],
        hops: 1,
        joins: [discoveredJoin],
        sql_fragment: '',
      },
    });

    const baseProps = {
      schemaIdentity: 'ldlite' as const,
      selectedTables: ['inventory.item__t', 'inventory.location__t'],
      tableDetails: {},
      onJoinModeChange,
      onCustomJoinsChange,
      onDefaultJoinsChange,
    };
    const { getByRole, rerender } = render(
      <JoinPanel {...baseProps} joinMode="auto" customJoins={[]} />,
    );

    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([
      expect.objectContaining({ relationship_id: 'item-effective-location' }),
    ]));
    fireEvent.click(getByRole('button', { name: 'Auto Joins' }));

    expect(onCustomJoinsChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        relationship_id: 'item-effective-location',
        pair_id: 'item-location',
        join_type: 'JOIN',
      }),
    ]);
    expect(onJoinModeChange).toHaveBeenLastCalledWith('manual');

    const lastCustomJoinCall = onCustomJoinsChange.mock.calls[
      onCustomJoinsChange.mock.calls.length - 1
    ];
    const seededJoins = lastCustomJoinCall[0];
    rerender(
      <JoinPanel {...baseProps} joinMode="manual" customJoins={seededJoins} />,
    );
    fireEvent.change(getByRole('combobox'), { target: { value: 'LEFT JOIN' } });

    expect(onCustomJoinsChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        relationship_id: 'item-effective-location',
        pair_id: 'item-location',
        join_type: 'LEFT JOIN',
      }),
    ]);
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

  it('clears a previous path immediately and never publishes a partial topology', async () => {
    const onDefaultJoinsChange = vi.fn();
    const itemLocationJoin = {
      from_table: 'inventory.item__t',
      from_column: 'effective_location_id',
      to_table: 'inventory.location__t',
      to_column: 'id',
      foreign_key: 'item_effective_location_fk',
      relationship_id: 'item-effective-location',
      pair_id: 'item-location',
    };
    findPath.mockResolvedValueOnce({
      path: {
        chain: ['inventory.item__t', 'inventory.location__t'],
        hops: 1,
        joins: [itemLocationJoin],
        sql_fragment: '',
      },
    });

    const props = {
      schemaIdentity: 'ldlite' as const,
      tableDetails: {},
      joinMode: 'manual' as const,
      customJoins: [{ ...itemLocationJoin, join_type: 'LEFT JOIN' as const }],
      onJoinModeChange: vi.fn(),
      onCustomJoinsChange: vi.fn(),
      onDefaultJoinsChange,
    };
    const { rerender } = render(
      <JoinPanel {...props} selectedTables={['inventory.item__t', 'inventory.location__t']} />,
    );

    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([
      expect.objectContaining({ relationship_id: 'item-effective-location' }),
    ]));

    const resolveUnreachable: Array<(value: { path: null }) => void> = [];
    findPath
      .mockResolvedValueOnce({
        path: {
          chain: ['inventory.item__t', 'inventory.location__t'],
          hops: 1,
          joins: [itemLocationJoin],
          sql_fragment: '',
        },
      })
      .mockImplementation(() => new Promise((resolve) => {
        resolveUnreachable.push(resolve);
      }));
    onDefaultJoinsChange.mockClear();

    rerender(
      <JoinPanel
        {...props}
        selectedTables={[
          'inventory.item__t',
          'inventory.location__t',
          'inventory.holdings_record__t',
        ]}
      />,
    );

    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenCalledWith([]));
    expect(onDefaultJoinsChange).toHaveBeenCalledTimes(1);

    await act(async () => {
      resolveUnreachable[0]({ path: null });
    });
    await waitFor(() => expect(resolveUnreachable).toHaveLength(2));
    await act(async () => {
      resolveUnreachable[1]({ path: null });
    });

    await waitFor(() => expect(findPath).toHaveBeenCalledTimes(4));
    expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([]);
    expect(onDefaultJoinsChange).not.toHaveBeenCalledWith([
      expect.objectContaining({ relationship_id: 'item-effective-location' }),
    ]);
  });

  it('ignores a stale discovery result after the selected topology changes', async () => {
    const onDefaultJoinsChange = vi.fn();
    const oldJoin = {
      from_table: 'inventory.item__t',
      from_column: 'effective_location_id',
      to_table: 'inventory.location__t',
      to_column: 'id',
      relationship_id: 'item-effective-location',
      pair_id: 'item-location',
    };
    const currentJoin = {
      from_table: 'inventory.item__t',
      from_column: 'holdings_record_id',
      to_table: 'inventory.holdings_record__t',
      to_column: 'id',
      relationship_id: 'item-holdings',
      pair_id: 'item-holdings-pair',
    };
    let resolveOldPath!: (value: { path: { joins: Array<typeof oldJoin> } }) => void;
    findPath
      .mockImplementationOnce(() => new Promise((resolve) => {
        resolveOldPath = resolve;
      }))
      .mockResolvedValueOnce({ path: { joins: [currentJoin] } });

    const props = {
      schemaIdentity: 'ldlite' as const,
      tableDetails: {},
      joinMode: 'auto' as const,
      customJoins: [],
      onJoinModeChange: vi.fn(),
      onCustomJoinsChange: vi.fn(),
      onDefaultJoinsChange,
    };
    const { rerender } = render(
      <JoinPanel {...props} selectedTables={['inventory.item__t', 'inventory.location__t']} />,
    );
    await waitFor(() => expect(findPath).toHaveBeenCalledTimes(1));

    rerender(
      <JoinPanel {...props} selectedTables={['inventory.item__t', 'inventory.holdings_record__t']} />,
    );
    await waitFor(() => expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([
      expect.objectContaining({ relationship_id: 'item-holdings' }),
    ]));

    await act(async () => {
      resolveOldPath({ path: { joins: [oldJoin] } });
    });

    expect(onDefaultJoinsChange).toHaveBeenLastCalledWith([
      expect.objectContaining({ relationship_id: 'item-holdings' }),
    ]);
  });
});
