import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import ResultsTable from './ResultsTable';

const truncationWarning =
  'This report reached its 100,000-row cap. Narrow the location to retrieve the remaining records.';

describe('ResultsTable truncation feedback', () => {
  afterEach(cleanup);

  it('shows a truncation warning above inline results', () => {
    render(
      <ResultsTable
        data={{
          columns: ['Instance UUID'],
          rows: [{ 'Instance UUID': 'instance-1' }],
          rowCount: 100000,
          executionTimeMs: 18,
          sql: 'select instance_uuid',
          truncated: true,
        }}
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent(truncationWarning);
  });

  it('keeps the full CSV download beside a file-result truncation warning', () => {
    render(
      <ResultsTable
        data={{
          columns: ['UUID'],
          rows: [{ UUID: 'instance-1' }],
          rowCount: 100000,
          executionTimeMs: 18,
          sql: 'select instance_uuid',
          outputMode: 'file',
          downloadUrl: '/api/query/export/job-8',
          truncated: true,
        }}
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent(truncationWarning);
    expect(screen.getByRole('link', { name: /Download Full CSV/i })).toHaveAttribute(
      'href',
      '/api/query/export/job-8',
    );
  });

  it('surfaces skipped non-conforming identifiers beside the export warning', () => {
    render(
      <ResultsTable
        data={{
          columns: ['UUID'],
          rows: [{ UUID: 'instance-1' }],
          rowCount: 1,
          executionTimeMs: 18,
          sql: 'select instance_uuid',
          outputMode: 'file',
          downloadUrl: '/api/query/export/job-9',
          identifierSkippedCount: 1,
        }}
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent(
      '1 identifier could not be exported because it was not a valid FOLIO UUID.',
    );
  });
});
