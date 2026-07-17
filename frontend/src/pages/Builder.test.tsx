import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Builder from './Builder';

const apiMocks = vi.hoisted(() => ({
  buildQuery: vi.fn(),
  submitQuery: vi.fn(),
  fetchSchema: vi.fn().mockResolvedValue({ metadata: {}, tables: {} }),
  fetchTableDetail: vi.fn().mockResolvedValue({
    table: { columns: [] },
    relationships: { parents: [], children: [] },
  }),
}));

vi.mock('../api/client', () => ({
  fetchSchema: apiMocks.fetchSchema,
  fetchTableDetail: apiMocks.fetchTableDetail,
  buildQuery: apiMocks.buildQuery,
  submitQuery: apiMocks.submitQuery,
  saveQuery: vi.fn(),
  downloadExportCsv: vi.fn(),
}));

vi.mock('../hooks/useJobPolling', () => ({
  useJobPolling: () => ({
    job: null,
    results: null,
    isRunning: false,
    error: null,
    cancel: vi.fn(),
    reset: vi.fn(),
    elapsedSeconds: 0,
  }),
}));

vi.mock('../components/TableBrowser', () => ({
  default: ({ onAddTable }: { onAddTable: (table: string) => void }) => (
    <>
      <button onClick={() => onAddTable('inventory.item__t')}>Add item</button>
      <button onClick={() => onAddTable('inventory.location__t')}>Add location</button>
      <button onClick={() => onAddTable('inventory.holdings_record__t')}>Toggle holdings</button>
    </>
  ),
}));

vi.mock('../components/ColumnPicker', () => ({
  default: ({ onColumnsChange }: { onColumnsChange: (columns: Array<{ table: string; column: string; aggregate: '' }>) => void }) => (
    <button onClick={() => onColumnsChange([{ table: 'inventory.item__t', column: 'id', aggregate: '' }])}>
      Select id
    </button>
  ),
}));

vi.mock('../components/SqlPreview', () => ({
  default: ({ sql, onChange, readOnly }: { sql: string; onChange?: (sql: string) => void; readOnly?: boolean }) => (
    <textarea
      aria-label="SQL Preview"
      value={sql}
      readOnly={readOnly}
      onChange={(event) => onChange?.(event.target.value)}
    />
  ),
}));

vi.mock('../components/RelationshipPanel', () => ({ default: () => null }));
vi.mock('../components/BuilderGraph', () => ({ default: () => null }));
vi.mock('../components/FilterPanel', () => ({ default: () => null }));
vi.mock('../components/SortPanel', () => ({ default: () => null }));
vi.mock('../components/JoinPanel', () => ({
  default: ({
    onJoinModeChange,
    onCustomJoinsChange,
    onDefaultJoinsChange,
    onRelationshipChange,
  }: {
    onJoinModeChange: (mode: 'auto' | 'manual') => void;
    onCustomJoinsChange: (joins: Array<{
      from_table: string;
      from_column: string;
      to_table: string;
      to_column: string;
      foreign_key: string;
      relationship_id: string;
      pair_id: string;
      join_type: 'LEFT JOIN';
    }>) => void;
    onDefaultJoinsChange?: (joins: Array<{
      from_table: string;
      from_column: string;
      to_table: string;
      to_column: string;
      foreign_key: string;
      relationship_id: string;
      pair_id: string;
      join_type: 'LEFT JOIN';
    }>) => void;
    onRelationshipChange?: (pairId: string, relationshipId: string) => void;
  }) => (
    <>
      <button
        onClick={() => onDefaultJoinsChange?.([{
          from_table: 'inventory.item__t',
          from_column: 'effective_location_id',
          to_table: 'inventory.location__t',
          to_column: 'id',
          foreign_key: 'item_effective_location_fk',
          relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
          pair_id: 'inventory.item__t<->inventory.location__t',
          join_type: 'LEFT JOIN',
        }])}
      >
        Discover default joins
      </button>
      <button
        onClick={() => onDefaultJoinsChange?.([
          {
            from_table: 'inventory.item__t',
            from_column: 'effective_location_id',
            to_table: 'inventory.location__t',
            to_column: 'id',
            foreign_key: 'item_effective_location_fk',
            relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
            pair_id: 'inventory.item__t<->inventory.location__t',
            join_type: 'LEFT JOIN',
          },
          {
            from_table: 'inventory.location__t',
            from_column: 'holdings_id',
            to_table: 'inventory.holdings_record__t',
            to_column: 'id',
            foreign_key: 'location_holdings_fk',
            relationship_id: 'inventory.location__t.holdings_id->inventory.holdings_record__t.id',
            pair_id: 'inventory.holdings_record__t<->inventory.location__t',
            join_type: 'LEFT JOIN',
          },
        ])}
      >
        Discover three-table joins
      </button>
      <button onClick={() => onDefaultJoinsChange?.([])}>Clear default joins</button>
      <button onClick={() => onDefaultJoinsChange?.([])}>Begin incomplete discovery</button>
      <button
        onClick={() => {
          const joins = [{
            from_table: 'inventory.item__t',
            from_column: 'effective_location_id',
            to_table: 'inventory.location__t',
            to_column: 'id',
            foreign_key: 'item_effective_location_fk',
            relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
            pair_id: 'inventory.item__t<->inventory.location__t',
            join_type: 'LEFT JOIN',
          }] as const;
          onDefaultJoinsChange?.([...joins]);
          onCustomJoinsChange([...joins]);
          onJoinModeChange('manual');
        }}
      >
        Use manual join
      </button>
      <button
        onClick={() => onRelationshipChange?.(
          'inventory.item__t<->inventory.location__t',
          'inventory.item__t.permanent_location_id->inventory.location__t.id',
        )}
      >
        Use permanent relationship
      </button>
    </>
  ),
}));
vi.mock('../components/ResultsTable', () => ({ default: () => null }));

describe('Builder', () => {
  afterEach(cleanup);

  beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(window, 'confirm').mockReturnValue(false);
  });

  it('submits generated params with edited SQL on initial run and confirmation retry', async () => {
    const generatedSql = 'SELECT id FROM inventory.item__t WHERE id LIKE :p0';
    const editedSql = `${generatedSql} ORDER BY id`;
    const params = { ':p0': '%general%' };
    let resolveInitialSubmit!: (response: { requiresConfirmation: boolean; estimatedRows: number }) => void;
    apiMocks.buildQuery.mockResolvedValue({ sql: generatedSql, params });
    apiMocks.submitQuery
      .mockImplementationOnce(() => new Promise((resolve) => {
        resolveInitialSubmit = resolve;
      }))
      .mockResolvedValueOnce({ jobId: 'job-1' });

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <Builder />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));

    expect(await screen.findByDisplayValue(generatedSql)).toBeInTheDocument();
    expect(apiMocks.buildQuery).toHaveBeenCalledWith(expect.objectContaining({
      schemaIdentity: 'ldlite',
      tables: ['inventory.item__t'],
      columns: [expect.objectContaining({ table: 'inventory.item__t' })],
    }));
    fireEvent.click(screen.getByRole('button', { name: 'Edit SQL' }));
    fireEvent.change(screen.getByLabelText('SQL Preview'), { target: { value: editedSql } });
    fireEvent.click(screen.getByRole('button', { name: 'Run' }));

    await waitFor(() => expect(apiMocks.submitQuery).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'DISTINCT' }));
    await act(() => {
      resolveInitialSubmit({ requiresConfirmation: true, estimatedRows: 50_000 });
    });

    await waitFor(() => expect(apiMocks.submitQuery).toHaveBeenCalledTimes(2));
    expect(apiMocks.submitQuery).toHaveBeenNthCalledWith(
      1,
      editedSql,
      params,
      'builder',
      'Builder: inventory.item__t',
      'folio',
      undefined,
    );
    expect(apiMocks.submitQuery).toHaveBeenNthCalledWith(
      2,
      editedSql,
      params,
      'builder',
      'Builder: inventory.item__t',
      'folio',
      { confirmed: true, outputMode: 'table' },
    );
  });

  it('builds a two-table manual query with trusted relationship selections only', async () => {
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT 1', params: {} });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <Builder />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    fireEvent.click(screen.getByRole('button', { name: 'Joins' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use manual join' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));

    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalled());
    expect(apiMocks.buildQuery).toHaveBeenCalledWith(expect.objectContaining({
      schemaIdentity: 'ldlite',
      tables: ['inventory.item__t', 'inventory.location__t'],
      joins: [{
        relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
        join_type: 'LEFT JOIN',
      }],
    }));
  });

  it('builds with a session relationship override from the complete default path', async () => {
    const effective = {
      from_table: 'inventory.item__t',
      from_column: 'effective_location_id',
      to_table: 'inventory.location__t',
      to_column: 'id',
      parent_table: 'inventory.location__t',
      parent_column: 'id',
      local_column: 'effective_location_id',
      foreign_key: 'Effective location',
      relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
      pair_id: 'inventory.item__t<->inventory.location__t',
      label: 'Effective location',
      is_default: true,
      source: 'overlay',
    };
    const permanent = {
      ...effective,
      from_column: 'permanent_location_id',
      local_column: 'permanent_location_id',
      relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
      label: 'Permanent location',
      is_default: false,
    };
    apiMocks.fetchTableDetail.mockImplementation(async (table: string) => ({
      name: table,
      table: { columns: [] },
      relationships: table === 'inventory.item__t'
        ? { parents: [effective, permanent], children: [] }
        : { parents: [], children: [effective, permanent] },
    }));
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT 1', params: {} });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><Builder /></MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    fireEvent.click(screen.getByRole('button', { name: 'Joins' }));
    await waitFor(() => expect(apiMocks.fetchTableDetail).toHaveBeenCalledTimes(2));
    fireEvent.click(screen.getByRole('button', { name: 'Discover default joins' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use permanent relationship' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));

    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalled());
    expect(apiMocks.buildQuery).toHaveBeenCalledWith(expect.objectContaining({
      joins: [{
        relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
        join_type: 'LEFT JOIN',
      }],
    }));
  });

  it('replaces the complete default path as tables are added and removed', async () => {
    const effective = {
      from_table: 'inventory.item__t',
      from_column: 'effective_location_id',
      to_table: 'inventory.location__t',
      to_column: 'id',
      parent_table: 'inventory.location__t',
      parent_column: 'id',
      local_column: 'effective_location_id',
      foreign_key: 'Effective location',
      relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
      pair_id: 'inventory.item__t<->inventory.location__t',
      label: 'Effective location',
      is_default: true,
      source: 'overlay',
    };
    const permanent = {
      ...effective,
      from_column: 'permanent_location_id',
      local_column: 'permanent_location_id',
      relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
      label: 'Permanent location',
      is_default: false,
    };
    apiMocks.fetchTableDetail.mockImplementation(async (table: string) => ({
      name: table,
      table: { columns: [] },
      relationships: table === 'inventory.item__t'
        ? { parents: [effective, permanent], children: [] }
        : table === 'inventory.location__t'
          ? { parents: [], children: [effective, permanent] }
          : { parents: [], children: [] },
    }));
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT 1', params: {} });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><Builder /></MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    fireEvent.click(screen.getByRole('button', { name: 'Joins' }));
    await waitFor(() => expect(apiMocks.fetchTableDetail).toHaveBeenCalledTimes(2));
    fireEvent.click(screen.getByRole('button', { name: 'Discover default joins' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use manual join' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use permanent relationship' }));

    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discover three-table joins' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));

    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({
      joins: [
        {
          relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
          join_type: 'LEFT JOIN',
        },
        {
          relationship_id: 'inventory.location__t.holdings_id->inventory.holdings_record__t.id',
          join_type: 'LEFT JOIN',
        },
      ],
    }));

    apiMocks.buildQuery.mockClear();
    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    fireEvent.click(screen.getByRole('button', { name: /Joins/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Discover default joins' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({
      joins: [{
        relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
        join_type: 'LEFT JOIN',
      }],
    }));

    apiMocks.buildQuery.mockClear();
    fireEvent.click(screen.getByRole('button', { name: /Joins/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Clear default joins' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({ joins: 'auto' }));
  });

  it('falls back to auto while a manual overridden topology is incomplete', async () => {
    const effective = {
      from_table: 'inventory.item__t',
      from_column: 'effective_location_id',
      to_table: 'inventory.location__t',
      to_column: 'id',
      parent_table: 'inventory.location__t',
      parent_column: 'id',
      local_column: 'effective_location_id',
      foreign_key: 'Effective location',
      relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
      pair_id: 'inventory.item__t<->inventory.location__t',
      label: 'Effective location',
      is_default: true,
      source: 'overlay',
    };
    const permanent = {
      ...effective,
      from_column: 'permanent_location_id',
      local_column: 'permanent_location_id',
      relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
      label: 'Permanent location',
      is_default: false,
    };
    apiMocks.fetchTableDetail.mockImplementation(async (table: string) => ({
      name: table,
      table: { columns: [] },
      relationships: table === 'inventory.item__t'
        ? { parents: [effective, permanent], children: [] }
        : table === 'inventory.location__t'
          ? { parents: [], children: [effective, permanent] }
          : { parents: [], children: [] },
    }));
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT 1', params: {} });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><Builder /></MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    fireEvent.click(screen.getByRole('button', { name: 'Joins' }));
    await waitFor(() => expect(apiMocks.fetchTableDetail).toHaveBeenCalledTimes(2));
    fireEvent.click(screen.getByRole('button', { name: 'Use manual join' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use permanent relationship' }));

    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    fireEvent.click(screen.getByRole('button', { name: 'Begin incomplete discovery' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));

    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({ joins: 'auto' }));
  });
});
