import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
    <button onClick={() => onAddTable('inventory.item__t')}>Add item</button>
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
vi.mock('../components/JoinPanel', () => ({ default: () => null }));
vi.mock('../components/ResultsTable', () => ({ default: () => null }));

describe('Builder', () => {
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
});
