import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ReportDetail from './ReportDetail';
import type { ReportParam, ReportTemplate } from '../types';

const { getReportMock, runReportMock, cancelJobMock, resetJobMock } = vi.hoisted(() => ({
  getReportMock: vi.fn(),
  runReportMock: vi.fn(),
  cancelJobMock: vi.fn(),
  resetJobMock: vi.fn(),
}));

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client');
  return {
    ...actual,
    getReport: getReportMock,
    runReport: runReportMock,
  };
});

vi.mock('../hooks/useJobPolling', () => ({
  useJobPolling: () => ({
    job: null,
    results: null,
    isRunning: false,
    error: null,
    cancel: cancelJobMock,
    reset: resetJobMock,
  }),
}));

vi.mock('../hooks/useAuth', () => ({
  useAuth: () => ({
    user: null,
    accessToken: null,
    loading: false,
    isAdmin: false,
    allocationColleges: [],
    canEditAllocationsFor: () => false,
    authEnabled: false,
    login: vi.fn(),
    logout: vi.fn(),
  }),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

beforeEach(() => {
  getReportMock.mockResolvedValue(combinationReport());
  runReportMock.mockResolvedValue({ jobId: 'synthetic-combination-job', status: 'pending', reportName: 'MARC Field Combination Finder', outputMode: 'file' });
});

function parameterDefinitions(): ReportParam[] {
  const parameters: ReportParam[] = [
    { name: 'locationIds', type: 'multiselect', label: 'Locations', required: true, default: '', resolvedDefault: '11111111-1111-4111-8111-111111111111', max_selections: 100 },
    { name: 'locationBasis', type: 'select', label: 'Location basis', required: true, default: 'effective_item', resolvedDefault: 'effective_item' },
    { name: 'combine', type: 'select', label: 'Combine conditions', required: true, default: 'all', resolvedDefault: 'all' },
  ];
  for (let slot = 1; slot <= 5; slot++) {
    const fields: Array<[string, ReportParam['type'], string, boolean]> = [
      ['marcTag', 'text', '', slot === 1],
      ['occurrenceCondition', 'select', 'has', false],
      ['firstIndicator', 'select', 'any', false],
      ['secondIndicator', 'select', 'any', false],
      ['subfieldCode', 'text', '', false],
      ['contentRule', 'select', 'any', false],
      ['searchValue', 'text', '', false],
      ['caseExact', 'select', 'false', false],
    ];
    for (const [field, type, defaultValue, required] of fields) {
      parameters.push({
        name: `c${slot}_${field}`,
        type,
        label: `Condition ${slot} ${field}`,
        required,
        default: defaultValue,
        resolvedDefault: defaultValue,
      });
    }
  }
  return parameters;
}

function combinationReport(): ReportTemplate {
  return {
    id: 29,
    slug: 'marc-field-combination-finder',
    name: 'MARC Field Combination Finder',
    description: 'Finds instances satisfying several MARC field conditions.',
    category: 'cataloging',
    sqlTemplate: 'select 1',
    parameters: parameterDefinitions(),
    defaultLimit: 100000,
    isActive: true,
    createdBy: 'manual',
    createdAt: '2026-11-05T00:00:00Z',
    updatedAt: '2026-11-05T00:00:00Z',
    selectOptions: {
      locationIds: [{ value: '11111111-1111-4111-8111-111111111111', label: 'Smith — Main [MAIN]' }],
      locationBasis: [{ value: 'effective_item', label: 'Effective item' }, { value: 'permanent_item', label: 'Permanent item' }],
      combine: [{ value: 'all', label: 'All conditions (AND)' }, { value: 'any', label: 'Any condition (OR)' }],
    },
    identifierExportAvailable: true,
  };
}

function searchParams(overrides: Record<string, string> = {}, autorun = false): string {
  const params = new URLSearchParams();
  params.set('rp.29.locationIds', '11111111-1111-4111-8111-111111111111');
  params.set('rp.29.locationBasis', 'effective_item');
  params.set('rp.29.combine', 'all');
  params.set('rp.29.c1_marcTag', '655');
  Object.entries(overrides).forEach(([key, value]) => params.set(`rp.29.${key}`, value));
  if (autorun) params.set('autorun', '29');
  return `?${params.toString()}`;
}

function LocationProbe() {
  const location = useLocation();
  return <output data-testid="location-search">{location.search}</output>;
}

function renderDetail(search: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/reports/29${search}`]}>
        <LocationProbe />
        <Routes><Route path="/reports/:id" element={<ReportDetail />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ReportDetail MARC combination behavior', () => {
  it('hides inactive cards after sparse URL hydration and does not inject inactive slot defaults', async () => {
    renderDetail(searchParams({ c2_firstIndicator: 'bad-from-url', unexpected: 'discard-me' }));

    expect(await screen.findByRole('heading', { name: 'MARC Field Combination Finder' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Condition 1' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Condition 2' })).not.toBeInTheDocument();
    await waitFor(() => {
      expect(screen.getByTestId('location-search')).not.toHaveTextContent('unexpected');
      expect(screen.getByTestId('location-search')).not.toHaveTextContent('c2_firstIndicator');
    });
  });

  it('gates Run Report when no condition is active or an active slot has invalid text criteria', async () => {
    const emptySearch = searchParams();
    const params = new URLSearchParams(emptySearch);
    params.delete('rp.29.c1_marcTag');
    const emptyView = renderDetail(`?${params.toString()}`);
    expect(await screen.findByRole('button', { name: 'Run Report' })).toBeDisabled();
    emptyView.unmount();

    renderDetail(searchParams({ c1_contentRule: 'contains', c1_searchValue: '' }));
    expect(await screen.findByRole('button', { name: 'Run Report' })).toBeDisabled();
    expect(await screen.findByText('Search text is required for this content rule.')).toBeInTheDocument();
  });

  it('keeps Missing and text criteria configured before entering the tag and submits them', async () => {
    const emptyParams = new URLSearchParams(searchParams());
    emptyParams.delete('rp.29.c1_marcTag');
    renderDetail(`?${emptyParams.toString()}`);

    fireEvent.change(await screen.findByLabelText('Condition 1 occurrence'), { target: { value: 'missing' } });
    fireEvent.change(screen.getByLabelText('Condition 1 content rule'), { target: { value: 'contains' } });
    fireEvent.change(await screen.findByLabelText('Condition 1 search text'), { target: { value: 'Needle' } });
    fireEvent.change(screen.getByLabelText('Condition 1 MARC tag'), { target: { value: '655' } });

    const runButton = screen.getByRole('button', { name: 'Run Report' });
    await waitFor(() => expect(runButton).toBeEnabled());
    fireEvent.click(runButton);
    await waitFor(() => expect(runReportMock).toHaveBeenCalledTimes(1));
    const [, params] = runReportMock.mock.calls[0];
    expect(Object.keys(params).sort()).toEqual(parameterDefinitions().map((parameter) => parameter.name).sort());
    expect(params).toMatchObject({
      c1_marcTag: '655',
      c1_occurrenceCondition: 'missing',
      c1_firstIndicator: 'any',
      c1_secondIndicator: 'any',
      c1_subfieldCode: '',
      c1_contentRule: 'contains',
      c1_searchValue: 'Needle',
      c1_caseExact: 'false',
    });
  });

  it('initializes declared condition defaults when an empty first slot becomes active', async () => {
    const emptyParams = new URLSearchParams(searchParams());
    emptyParams.delete('rp.29.c1_marcTag');
    renderDetail(`?${emptyParams.toString()}`);

    const tag = await screen.findByLabelText('Condition 1 MARC tag');
    fireEvent.change(tag, { target: { value: '655' } });
    const runButton = screen.getByRole('button', { name: 'Run Report' });
    const canRun = !runButton.hasAttribute('disabled');
    expect.soft(runButton).toBeEnabled();
    if (!canRun) return;

    fireEvent.click(runButton);
    await waitFor(() => expect(runReportMock).toHaveBeenCalledTimes(1));
    const [, params] = runReportMock.mock.calls[0];
    expect(Object.keys(params).sort()).toEqual(parameterDefinitions().map((parameter) => parameter.name).sort());
    expect(params).toMatchObject({
      c1_marcTag: '655',
      c1_occurrenceCondition: 'has',
      c1_firstIndicator: 'any',
      c1_secondIndicator: 'any',
      c1_subfieldCode: '',
      c1_contentRule: 'any',
      c1_searchValue: '',
      c1_caseExact: 'false',
    });
    for (let slot = 2; slot <= 5; slot++) {
      for (const field of ['marcTag', 'occurrenceCondition', 'firstIndicator', 'secondIndicator', 'subfieldCode', 'contentRule', 'searchValue', 'caseExact']) {
        expect(params[`c${slot}_${field}`]).toBe('');
      }
    }
  });

  it('reinitializes declared defaults after clearing and re-entering a condition tag', async () => {
    renderDetail(searchParams());
    const tag = await screen.findByLabelText('Condition 1 MARC tag');
    fireEvent.change(tag, { target: { value: '' } });
    fireEvent.change(tag, { target: { value: '655' } });

    const runButton = screen.getByRole('button', { name: 'Run Report' });
    const canRun = !runButton.hasAttribute('disabled');
    expect.soft(runButton).toBeEnabled();
    if (!canRun) return;

    fireEvent.click(runButton);
    await waitFor(() => expect(runReportMock).toHaveBeenCalledTimes(1));
    const [, params] = runReportMock.mock.calls[0];
    expect(params).toMatchObject({
      c1_marcTag: '655',
      c1_occurrenceCondition: 'has',
      c1_firstIndicator: 'any',
      c1_secondIndicator: 'any',
      c1_subfieldCode: '',
      c1_contentRule: 'any',
      c1_searchValue: '',
      c1_caseExact: 'false',
    });
  });

  it('submits only the 43 declared string keys and sends inactive slot values as empty strings', async () => {
    renderDetail(searchParams({ unexpected: 'ignore-me', c2_marcTag: '', c2_searchValue: 'stale' }));
    const runButton = await screen.findByRole('button', { name: 'Run Report' });
    await waitFor(() => expect(runButton).toBeEnabled());
    fireEvent.click(runButton);

    await waitFor(() => expect(runReportMock).toHaveBeenCalledTimes(1));
    const [, params, options] = runReportMock.mock.calls[0];
    expect(Object.keys(params).sort()).toEqual(parameterDefinitions().map((parameter) => parameter.name).sort());
    expect(params.unexpected).toBeUndefined();
    expect(params.c2_marcTag).toBe('');
    expect(params.c2_searchValue).toBe('');
    expect(params.c1_marcTag).toBe('655');
    expect(options).toEqual({ outputMode: 'table', exportKind: undefined });
  });

  it('wires Run, CSV, and UUID export with the selected combination values', async () => {
    renderDetail(searchParams({ c1_searchValue: 'Fiction', c1_contentRule: 'contains' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Run Report' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
    await waitFor(() => expect(runReportMock).toHaveBeenLastCalledWith(
      29,
      expect.objectContaining({ c1_marcTag: '655', c1_searchValue: 'Fiction' }),
      { outputMode: 'file', exportKind: 'worklist' },
    ));
    fireEvent.click(screen.getByRole('button', { name: 'Export FOLIO UUID list' }));
    await waitFor(() => expect(runReportMock).toHaveBeenLastCalledWith(
      29,
      expect.objectContaining({ c1_marcTag: '655', c1_searchValue: 'Fiction' }),
      { outputMode: 'file', exportKind: 'identifier' },
    ));
  });

  it('autoruns a valid hydrated combination once and clears its autorun marker', async () => {
    renderDetail(searchParams({ c1_contentRule: 'contains', c1_searchValue: 'Fiction' }, true));
    await waitFor(() => expect(runReportMock).toHaveBeenCalledTimes(1));
    expect(runReportMock).toHaveBeenCalledWith(29, expect.objectContaining({ c1_marcTag: '655' }), {
      outputMode: 'table',
      exportKind: undefined,
    });
    await waitFor(() => expect(screen.getByTestId('location-search')).not.toHaveTextContent('autorun'));
  });
});
