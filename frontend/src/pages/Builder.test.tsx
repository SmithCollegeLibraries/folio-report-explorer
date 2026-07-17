import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useLayoutEffect } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Builder, { resolvedJoinsForTopology } from './Builder';

const apiMocks = vi.hoisted(() => ({
  buildQuery: vi.fn(),
  submitQuery: vi.fn(),
  saveQuery: vi.fn(),
  fetchSchema: vi.fn().mockResolvedValue({ metadata: {}, tables: {} }),
  fetchTableDetail: vi.fn().mockResolvedValue({
    table: { columns: [] },
    relationships: { parents: [], children: [] },
  }),
  findPath: vi.fn(),
}));

const uiTestControls = vi.hoisted(() => ({
  clickBuildOnThreeTableLayout: false,
}));

vi.mock('../api/client', () => ({
  fetchSchema: apiMocks.fetchSchema,
  fetchTableDetail: apiMocks.fetchTableDetail,
  findPath: apiMocks.findPath,
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
  default: ({ onAddTable, selectedTables }: { onAddTable: (table: string) => void; selectedTables: string[] }) => {
    useLayoutEffect(() => {
      if (uiTestControls.clickBuildOnThreeTableLayout && selectedTables.length === 3) {
        screen.getByRole('button', { name: 'Build SQL' }).click();
      }
    }, [selectedTables]);
    return (
      <>
        <button onClick={() => onAddTable('inventory.item__t')}>Add item</button>
        <button onClick={() => onAddTable('inventory.location__t')}>Add location</button>
        <button onClick={() => onAddTable('inventory.holdings_record__t')}>Toggle holdings</button>
      </>
    );
  },
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

vi.mock('../components/RelationshipPanel', () => ({
  default: ({ onShowGraph }: { onShowGraph: () => void }) => (
    <button onClick={onShowGraph}>Show relationship graph</button>
  ),
}));
vi.mock('../components/BuilderGraph', () => ({
  default: ({ onRelationshipChange }: { onRelationshipChange?: (pairId: string, relationshipId: string) => void }) => (
    <>
      <button onClick={() => onRelationshipChange?.(
        'inventory.item__t<->inventory.location__t',
        'inventory.item__t.permanent_location_id->inventory.location__t.id',
      )}>Graph permanent relationship</button>
      <button onClick={() => onRelationshipChange?.(
        'inventory.item__t<->inventory.location__t',
        'inventory.item__t.temporary_location_id->inventory.location__t.id',
      )}>Graph temporary relationship</button>
    </>
  ),
}));
vi.mock('../components/FilterPanel', () => ({ default: () => null }));
vi.mock('../components/SortPanel', () => ({ default: () => null }));
vi.mock('../components/JoinPanel', () => ({
  default: ({
    onJoinModeChange,
    onCustomJoinsChange,
    onDefaultJoinsChange,
    onRelationshipChange,
    onResetRelationships,
    defaultJoins,
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
    defaultJoins?: Array<{ relationship_id: string }>;
  }) => (
    <>
      <output data-testid="builder-default-joins">
        {(defaultJoins ?? []).map((join) => join.relationship_id).join(',')}
      </output>
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
    uiTestControls.clickBuildOnThreeTableLayout = false;
    apiMocks.findPath.mockImplementation(async (_source: string, target: string) => ({
      path: {
        joins: [target === 'inventory.holdings_record__t' ? {
          from_table: 'inventory.location__t',
          from_column: 'holdings_id',
          to_table: 'inventory.holdings_record__t',
          to_column: 'id',
          relationship_id: 'inventory.location__t.holdings_id->inventory.holdings_record__t.id',
          pair_id: 'inventory.holdings_record__t<->inventory.location__t',
          join_type: 'LEFT JOIN',
        } : {
          from_table: 'inventory.item__t',
          from_column: 'effective_location_id',
          to_table: 'inventory.location__t',
          to_column: 'id',
          relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
          pair_id: 'inventory.item__t<->inventory.location__t',
          join_type: 'JOIN',
        }],
      },
    }));
    vi.spyOn(window, 'confirm').mockReturnValue(false);
  });

  it('projects no resolved joins when they belong to a previous table topology', () => {
    const oldJoins = [{ relationship_id: 'old-path' }] as Parameters<typeof resolvedJoinsForTopology>[0];
    expect(resolvedJoinsForTopology(
      oldJoins,
      'inventory.item__t\u001finventory.location__t',
      'inventory.item__t\u001finventory.location__t\u001finventory.holdings_record__t',
    )).toEqual([]);
  });

  it('discovers defaults without mounting Joins and builds a graph-selected alternate', async () => {
    const effective = {
      from_table: 'inventory.item__t', from_column: 'effective_location_id',
      to_table: 'inventory.location__t', to_column: 'id',
      parent_table: 'inventory.location__t', parent_column: 'id', local_column: 'effective_location_id',
      foreign_key: 'Effective location',
      relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
      pair_id: 'inventory.item__t<->inventory.location__t', label: 'Effective location', is_default: true, source: 'overlay',
    };
    const permanent = { ...effective, from_column: 'permanent_location_id', local_column: 'permanent_location_id',
      relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id', label: 'Permanent location', is_default: false };
    apiMocks.fetchTableDetail.mockImplementation(async (table: string) => ({
      name: table, table: { columns: [] },
      relationships: table === 'inventory.item__t' ? { parents: [effective, permanent], children: [] } : { parents: [], children: [effective, permanent] },
    }));
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT permanent', params: {} });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalled());
    await waitFor(() => expect(apiMocks.fetchTableDetail).toHaveBeenCalledTimes(2));
    fireEvent.click(screen.getByRole('button', { name: 'Relationships' }));
    fireEvent.click(screen.getByRole('button', { name: 'Show relationship graph' }));
    fireEvent.click(screen.getByRole('button', { name: 'Graph permanent relationship' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledWith(expect.objectContaining({
      joins: [expect.objectContaining({
        relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
      })],
    })));
  });

  it('ignores a stale build response after the relationship changes', async () => {
    const effective = {
      from_table: 'inventory.item__t', from_column: 'effective_location_id', to_table: 'inventory.location__t', to_column: 'id',
      parent_table: 'inventory.location__t', parent_column: 'id', local_column: 'effective_location_id', foreign_key: 'Effective',
      relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id', pair_id: 'inventory.item__t<->inventory.location__t',
      label: 'Effective', is_default: true, source: 'overlay',
    };
    const permanent = { ...effective, from_column: 'permanent_location_id', local_column: 'permanent_location_id',
      relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id', label: 'Permanent', is_default: false };
    const temporary = { ...effective, from_column: 'temporary_location_id', local_column: 'temporary_location_id',
      relationship_id: 'inventory.item__t.temporary_location_id->inventory.location__t.id', label: 'Temporary', is_default: false };
    apiMocks.fetchTableDetail.mockImplementation(async (table: string) => ({
      name: table, table: { columns: [] },
      relationships: table === 'inventory.item__t' ? { parents: [effective, permanent, temporary], children: [] } : { parents: [], children: [effective, permanent, temporary] },
    }));
    let resolvePermanent!: (value: { sql: string; params: Record<string, string> }) => void;
    apiMocks.buildQuery.mockImplementationOnce(() => new Promise((resolve) => { resolvePermanent = resolve; }));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(apiMocks.fetchTableDetail).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Relationships' }));
    fireEvent.click(screen.getByRole('button', { name: 'Show relationship graph' }));
    fireEvent.click(screen.getByRole('button', { name: 'Graph permanent relationship' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Graph temporary relationship' }));
    await act(async () => resolvePermanent({ sql: 'SELECT stale_permanent', params: {} }));
    expect(screen.queryByDisplayValue('SELECT stale_permanent')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Run' })).not.toBeInTheDocument();
  });

  it('ignores stale default-path discovery after topology changes', async () => {
    let resolveOld!: (value: { path: { joins: Array<Record<string, string>> } }) => void;
    const oldJoin = {
      from_table: 'inventory.item__t', from_column: 'effective_location_id', to_table: 'inventory.location__t', to_column: 'id',
      relationship_id: 'old-item-location', pair_id: 'old-pair', join_type: 'JOIN',
    };
    const currentJoin = {
      from_table: 'inventory.item__t', from_column: 'holdings_record_id', to_table: 'inventory.holdings_record__t', to_column: 'id',
      relationship_id: 'current-item-holdings', pair_id: 'current-pair', join_type: 'JOIN',
    };
    apiMocks.findPath
      .mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; }))
      .mockResolvedValueOnce({ path: { joins: [currentJoin] } });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    fireEvent.click(screen.getByRole('button', { name: 'Joins' }));
    await waitFor(() => expect(screen.getByTestId('builder-default-joins')).toHaveTextContent('current-item-holdings'));
    await act(async () => resolveOld({ path: { joins: [oldJoin] } }));
    expect(screen.getByTestId('builder-default-joins')).toHaveTextContent('current-item-holdings');
    expect(screen.getByTestId('builder-default-joins')).not.toHaveTextContent('old-item-location');
  });

  it('snapshots save fields and blocks duplicate submit and cancel while pending', async () => {
    let resolveSave!: (value: { id: number }) => void;
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT id', params: {} });
    apiMocks.saveQuery.mockImplementation(() => new Promise((resolve) => { resolveSave = resolve; }));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await screen.findByDisplayValue('SELECT id');
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    const dialog = screen.getByRole('dialog', { name: 'Save Query' });
    const name = within(dialog).getByPlaceholderText('Query name');
    fireEvent.change(name, { target: { value: 'Snapshot title' } });
    const submit = within(dialog).getByRole('button', { name: 'Save' });
    fireEvent.click(submit);
    fireEvent.click(submit);
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled());
    expect(name).toBeDisabled();
    fireEvent.change(name, { target: { value: 'Mutated after submit' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('dialog', { name: 'Save Query' })).toBeInTheDocument();
    expect(apiMocks.saveQuery).toHaveBeenCalledTimes(1);
    expect(apiMocks.saveQuery).toHaveBeenCalledWith(expect.objectContaining({ name: 'Snapshot title' }));
    await act(async () => resolveSave({ id: 1 }));
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Save Query' })).not.toBeInTheDocument());
  });

  it('preserves edited SQL when no alternate relationship requires substitution', async () => {
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT id', params: {} });
    apiMocks.saveQuery.mockResolvedValue({ id: 1 });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await screen.findByDisplayValue('SELECT id');
    fireEvent.click(screen.getByRole('button', { name: 'Edit SQL' }));
    fireEvent.change(screen.getByLabelText('SQL Preview'), { target: { value: 'SELECT id /* edited */' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    const dialog = screen.getByRole('dialog', { name: 'Save Query' });
    fireEvent.change(within(dialog).getByPlaceholderText('Query name'), { target: { value: 'Edited' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(apiMocks.saveQuery).toHaveBeenCalledWith(expect.objectContaining({
      generatedSql: 'SELECT id /* edited */',
      sqlEdited: true,
    })));
  });

  it('discloses that edited alternate SQL is not persisted', async () => {
    apiMocks.buildQuery
      .mockResolvedValueOnce({ sql: 'SELECT permanent_location_id', params: {} })
      .mockResolvedValueOnce({ sql: 'SELECT effective_location_id', params: {} });
    apiMocks.saveQuery.mockResolvedValue({ id: 1 });
    const effective = {
      from_table: 'inventory.item__t', from_column: 'effective_location_id', to_table: 'inventory.location__t', to_column: 'id',
      parent_table: 'inventory.location__t', parent_column: 'id', local_column: 'effective_location_id', foreign_key: 'Effective',
      relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id', pair_id: 'inventory.item__t<->inventory.location__t', label: 'Effective', is_default: true, source: 'overlay',
    };
    const permanent = { ...effective, from_column: 'permanent_location_id', local_column: 'permanent_location_id',
      relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id', label: 'Permanent', is_default: false };
    apiMocks.fetchTableDetail.mockImplementation(async (table: string) => ({
      name: table, table: { columns: [] }, relationships: table === 'inventory.item__t' ? { parents: [effective, permanent], children: [] } : { parents: [], children: [effective, permanent] },
    }));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(apiMocks.fetchTableDetail).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Relationships' }));
    fireEvent.click(screen.getByRole('button', { name: 'Show relationship graph' }));
    fireEvent.click(screen.getByRole('button', { name: 'Graph permanent relationship' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await screen.findByDisplayValue('SELECT permanent_location_id');
    fireEvent.click(screen.getByRole('button', { name: 'Edit SQL' }));
    fireEvent.change(screen.getByLabelText('SQL Preview'), { target: { value: 'SELECT edited_permanent' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(screen.getByText('Session SQL edits are not persisted when an alternate table link is active.')).toBeInTheDocument();
    const dialog = screen.getByRole('dialog', { name: 'Save Query' });
    fireEvent.change(within(dialog).getByPlaceholderText('Query name'), { target: { value: 'Default only' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(apiMocks.saveQuery).toHaveBeenCalledWith(expect.objectContaining({
      generatedSql: 'SELECT effective_location_id', sqlEdited: false,
    })));
    expect(screen.getByDisplayValue('SELECT edited_permanent')).toBeInTheDocument();
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
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalled());
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
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Use permanent relationship' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));

    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalled());
    expect(apiMocks.buildQuery).toHaveBeenCalledWith(expect.objectContaining({
      joins: [{
        relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
        join_type: 'JOIN',
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
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Use manual join' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use permanent relationship' }));

    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalledWith(
      expect.any(String), 'inventory.holdings_record__t', false, 6, 'ldlite',
    ));
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
          join_type: 'JOIN',
        },
      ],
    }));

    apiMocks.buildQuery.mockClear();
    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    fireEvent.click(screen.getByRole('button', { name: /Joins/ }));
    await waitFor(() => expect(apiMocks.findPath).toHaveBeenLastCalledWith(
      'inventory.item__t', 'inventory.location__t', false, 6, 'ldlite',
    ));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({
      joins: [{
        relationship_id: 'inventory.item__t.permanent_location_id->inventory.location__t.id',
        join_type: 'LEFT JOIN',
      }],
    }));

    apiMocks.buildQuery.mockClear();
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    await waitFor(() => expect(apiMocks.buildQuery).toHaveBeenCalledTimes(1));
    expect(apiMocks.buildQuery).toHaveBeenLastCalledWith(expect.objectContaining({ joins: 'auto' }));
  });

  it('guards Build while a manual overridden topology is incomplete', async () => {
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
    await waitFor(() => expect(screen.getByRole('button', { name: 'Build SQL' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Use manual join' }));
    fireEvent.click(screen.getByRole('button', { name: 'Use permanent relationship' }));

    apiMocks.findPath.mockImplementation((_source: string, target: string) => {
      if (target === 'inventory.holdings_record__t') return new Promise(() => {});
      return Promise.resolve({ path: { joins: [{
        from_table: 'inventory.item__t', from_column: 'effective_location_id',
        to_table: 'inventory.location__t', to_column: 'id',
        relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
        pair_id: 'inventory.item__t<->inventory.location__t', join_type: 'JOIN',
      }] } });
    });
    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Build SQL' })).toBeDisabled());
    fireEvent.click(screen.getByRole('button', { name: 'Build SQL' }));
    expect(apiMocks.buildQuery).not.toHaveBeenCalled();
  });

  it('cannot build with the previous resolved path immediately after the selected-table topology changes', async () => {
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT stale_path', params: {} });
    let resolveHoldingsPath!: (value: { path: { joins: Array<Record<string, string>> } }) => void;
    apiMocks.findPath.mockImplementation((_source: string, target: string) => {
      if (target === 'inventory.holdings_record__t') {
        return new Promise((resolve) => { resolveHoldingsPath = resolve; });
      }
      return Promise.resolve({ path: { joins: [{
        from_table: 'inventory.item__t', from_column: 'effective_location_id',
        to_table: 'inventory.location__t', to_column: 'id',
        relationship_id: 'inventory.item__t.effective_location_id->inventory.location__t.id',
        pair_id: 'inventory.item__t<->inventory.location__t', join_type: 'JOIN',
      }] } });
    });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Build SQL' })).toBeEnabled());

    uiTestControls.clickBuildOnThreeTableLayout = true;
    fireEvent.click(screen.getByRole('button', { name: 'Toggle holdings' }));
    const buildButton = screen.getByRole('button', { name: 'Build SQL' });
    expect(buildButton).toBeDisabled();
    expect(apiMocks.buildQuery).not.toHaveBeenCalled();

    await waitFor(() => expect(resolveHoldingsPath).toBeTypeOf('function'));
    await act(async () => resolveHoldingsPath({ path: { joins: [] } }));
  });

  it.each([
    ['a rejected discovery request', () => Promise.reject(new Error('path service unavailable'))],
    ['a completed null path', () => Promise.resolve({ path: null })],
  ])('keeps Build disabled after %s', async (_label, discoveryResult) => {
    apiMocks.findPath.mockImplementation(discoveryResult);
    apiMocks.buildQuery.mockResolvedValue({ sql: 'SELECT unsafe', params: {} });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={queryClient}><MemoryRouter><Builder /></MemoryRouter></QueryClientProvider>);

    fireEvent.click(await screen.findByRole('button', { name: 'Add item' }));
    fireEvent.click(screen.getByRole('button', { name: 'Select id' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add location' }));

    await waitFor(() => expect(apiMocks.findPath).toHaveBeenCalled());
    const buildButton = screen.getByRole('button', { name: 'Build SQL' });
    await waitFor(() => expect(buildButton).toBeDisabled());
    fireEvent.click(buildButton);
    expect(apiMocks.buildQuery).not.toHaveBeenCalled();
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
