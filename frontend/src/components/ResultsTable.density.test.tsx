import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ResultsTable from './ResultsTable';
import type { ExecuteResponse } from '../types';

const resultData: ExecuteResponse = {
  columns: ['title', 'instance_number', 'call_number'],
  rows: [
    {
      title: 'Collection art contemporain',
      instance_number: 'in0000001',
      call_number: 'N6490 .C65',
    },
  ],
  rowCount: 1,
  executionTimeMs: 42,
  sql: 'select title, instance_number, call_number from query_results',
};

describe('ResultsTable density', () => {
  it('uses compact spacing so query results stay readable in the Ask workspace', () => {
    render(<ResultsTable data={resultData} />);

    expect(screen.getByTestId('results-summary-bar')).toHaveClass('px-2', 'py-1');
    expect(screen.getByTestId('results-table')).toHaveClass('text-xs');
    expect(screen.getByRole('columnheader', { name: /title/i })).toHaveClass('px-2', 'py-1.5');
    expect(screen.getByRole('cell', { name: 'Collection art contemporain' })).toHaveClass('px-2', 'py-1');
  });
});
