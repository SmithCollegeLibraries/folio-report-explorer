import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Builder from './Builder';

const apiMocks = vi.hoisted(() => ({
  buildQuery: vi.fn(),
  submitQuery: vi.fn(),
  saveQuery: vi.fn(),
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
  saveQuery: apiMocks.saveQuery,
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
    onResetRelationships,
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
    onResetRelationships?: () => void;
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
      <button onClick={() => onResetRelationships?.()}>Reset relationships</button>
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

    apiMocks.buildQuery.mockClear();
    fireEvent.click(screen.getByRole('button', { name: 'Joins' }));
    fireEvent.click(screen.getByRole('button', { name: 'Reset relationships' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({ joins: 'auto' }));
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

  it('invalidates generated and edited SQL when an unavailable relationship override is pruned', async () => {
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
    const itemDetail = {
      name: 'inventory.item__t',
      table: { columns: [] },
      relationships: { parents: [effective, permanent], children: [] },
    };
    let resolveLocationDetail!: (detail: {
      name: string;
      table: { columns: never[] };
      relationships: { parents: never[]; children: typeof effective[] };
    }) => void;
    apiMocks.fetchTableDetail.mockImplementation((table: string) => {
      if (table === 'inventory.item__t') return Promise.resolve(itemDetail);
      return new Promise((resolve) => {
        resolveLocationDetail = resolve;
      });
    });
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT alternate_path', params: {} });
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
    expect(await screen.findByDisplayValue('SELECT alternate_path')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Edit SQL' }));
    fireEvent.change(screen.getByLabelText('SQL Preview'), {
      target: { value: 'SELECT stale_alternate_path' },
    });
    expect(screen.getByRole('button', { name: 'Run' })).toBeInTheDocument();

    itemDetail.relationships.parents = [effective];
    await act(async () => {
      resolveLocationDetail({
        name: 'inventory.location__t',
        table: { columns: [] },
        relationships: { parents: [], children: [effective] },
      });
    });

    expect(await screen.findByRole('status')).toHaveTextContent(
      'A selected table link is no longer available. Query Builder restored the default link.',
    );
    expect(screen.queryByRole('button', { name: 'Run' })).not.toBeInTheDocument();
    expect(screen.queryByDisplayValue('SELECT stale_alternate_path')).not.toBeInTheDocument();
  });

  it('rebuilds and saves default joins without replacing the active alternate SQL', async () => {
    apiMocks.buildQuery.mockReset();
    apiMocks.saveQuery.mockReset();
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
    apiMocks.buildQuery
      .mockResolvedValueOnce({ sql: 'SELECT permanent_location_id', params: {} })
      .mockResolvedValueOnce({ sql: 'SELECT effective_location_id', params: {} });
    apiMocks.submitQuery.mockResolvedValue({ jobId: 'job-permanent' });
    apiMocks.saveQuery.mockResolvedValue({ id: 42 });
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

    expect(await screen.findByDisplayValue('SELECT permanent_location_id')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Run' }));
    await waitFor(() => expect(apiMocks.submitQuery).toHaveBeenCalledWith(
      'SELECT permanent_location_id',
      {},
      'builder',
      'Builder: inventory.item__t, inventory.location__t',
      'folio',
      undefined,
    ));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(screen.getByText(
      'Alternate joins apply to this session only. Saved queries use the default table links.',
    )).toBeInTheDocument();
    const saveDialog = screen.getByRole('dialog', { name: 'Save Query' });
    fireEvent.change(within(saveDialog).getByPlaceholderText('Query name'), { target: { value: 'Locations' } });
    fireEvent.click(within(saveDialog).getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(apiMocks.saveQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({
      schemaIdentity: 'ldlite',
      joins: 'auto',
    }));
    expect(apiMocks.saveQuery).toHaveBeenCalledWith(expect.objectContaining({
      queryDefinition: expect.objectContaining({
        schemaIdentity: 'ldlite',
        joins: 'auto',
      }),
      generatedSql: expect.stringContaining('effective_location_id'),
    }));
    expect(screen.getByDisplayValue('SELECT permanent_location_id')).toBeInTheDocument();
  });

  it('keeps the save dialog open and does not save when the default rebuild fails', async () => {
    apiMocks.buildQuery.mockReset();
    apiMocks.saveQuery.mockReset();
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
    apiMocks.buildQuery
      .mockResolvedValueOnce({ sql: 'SELECT permanent_location_id', params: {} })
      .mockRejectedValueOnce(new Error('default build failed'));
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
    expect(await screen.findByDisplayValue('SELECT permanent_location_id')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    const saveDialog = screen.getByRole('dialog', { name: 'Save Query' });
    fireEvent.change(within(saveDialog).getByPlaceholderText('Query name'), { target: { value: 'Locations' } });
    fireEvent.click(within(saveDialog).getByRole('button', { name: 'Save' }));

    expect(await screen.findByText(
      'Could not rebuild the default joins. The query was not saved.',
    )).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Save Query' })).toBeInTheDocument();
    expect(apiMocks.saveQuery).not.toHaveBeenCalled();
    expect(screen.getByDisplayValue('SELECT permanent_location_id')).toBeInTheDocument();
  });
});
