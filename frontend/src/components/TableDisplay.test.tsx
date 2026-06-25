import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TableBrowser from './TableBrowser';
import TableList from './TableList';
import type { TableSummary } from '../types';

const tables: Record<string, TableSummary> = {
  inventory_items: {
    name: 'inventory_items',
    sql_name: 'inventory.item__t',
    type: 'TABLE',
    primary_key: 'id',
    remarks: null,
    column_count: 12,
    parent_count: 2,
    child_count: 1,
    domain: 'inventory',
  },
};

describe('table display names', () => {
  afterEach(() => cleanup());

  it('shows the physical Postgres table name first in Schema Explorer', () => {
    render(
      <TableList
        tables={tables}
        selectedTable={null}
        onSelectTable={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /inventory/i }));

    expect(screen.getByText('item__t')).toBeInTheDocument();
    expect(screen.getByText('inventory.item__t')).toBeInTheDocument();
    expect(screen.getByText('alias: inventory_items')).toBeInTheDocument();
  });

  it('shows the physical Postgres table name first in Query Builder', () => {
    render(
      <TableBrowser
        tables={tables}
        selectedTables={[]}
        tableDetails={{}}
        onAddTable={vi.fn()}
        onRemoveTable={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /inventory/i }));

    expect(screen.getByText('item__t')).toBeInTheDocument();
    expect(screen.getByText('inventory.item__t')).toBeInTheDocument();
    expect(screen.getByText('alias: inventory_items')).toBeInTheDocument();
  });
});
