import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import ReportDetail from './ReportDetail';
import Reports from './Reports';

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client');

  return {
    ...actual,
    listReports: vi.fn().mockResolvedValue({
      acquisitions: [
        {
          id: 1,
          slug: 'budget-report',
          name: 'Budget Report by Material Type',
          description: 'Summarizes expenditures by material type.',
          category: 'acquisitions',
          parameterCount: 3,
          defaultLimit: 10000,
          createdBy: 'system',
          createdAt: '2026-04-01T00:00:00Z',
        },
      ],
      finance: [
        {
          id: 5,
          slug: 'fund-allocation',
          name: 'Fund Allocation by Fiscal Year',
          description: 'Allocated budget amounts per fiscal year.',
          category: 'finance',
          parameterCount: 1,
          defaultLimit: 10000,
          createdBy: 'system',
          createdAt: '2026-04-01T00:00:00Z',
        },
      ],
      cataloging: [
        {
          id: 8,
          slug: 'marc-bibliographic-records-missing-tag',
          name: 'MARC Bibliographic Records Missing a Tag',
          description: 'Finds MARC bibliographic records missing a selected tag.',
          category: 'cataloging',
          parameterCount: 3,
          defaultLimit: 100000,
          createdBy: 'manual',
          createdAt: '2026-08-06T00:00:00Z',
        },
        {
          id: 9,
          slug: 'marc-field-indicator-content-finder',
          name: 'MARC Field, Indicator, and Content Finder',
          description: 'Finds present or missing MARC field rows by location and content.',
          category: 'cataloging',
          parameterCount: 10,
          defaultLimit: 100000,
          createdBy: 'manual',
          createdAt: '2026-08-06T00:00:00Z',
        },
      ],
    }),
    deleteReport: vi.fn(),
    getReport: vi.fn(),
    runReport: vi.fn(),
    createReport: vi.fn(),
    generateReportTemplate: vi.fn(),
    convertReportFromPhp: vi.fn(),
  };
});

afterEach(cleanup);

describe('Reports', () => {
  it('shows grouped category sections on the reports landing page', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports']}>
          <Routes>
            <Route path="/reports" element={<Reports />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Acquisitions' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Finance' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Cataloging' })).toBeInTheDocument();
    expect(screen.getByText('MARC Field, Indicator, and Content Finder')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^All$/i })).not.toBeInTheDocument();
  });

  it('lets you collapse and reopen report parameters on the detail page', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    });

    const { getReport } = await import('../api/client');
    vi.mocked(getReport).mockResolvedValue({
      id: 1,
      slug: 'budget-report',
      name: 'Budget Report by Material Type',
      description: 'Summarizes expenditures by material type.',
      category: 'acquisitions',
      sqlTemplate: 'select 1',
      parameters: [
        {
          name: 'campus',
          type: 'text',
          label: 'Campus',
          required: false,
          default: '',
          resolvedDefault: '',
          placeholder: 'Choose campus',
        },
      ],
      defaultLimit: 10000,
      isActive: true,
      createdBy: 'manual',
      createdAt: '2026-04-01T00:00:00Z',
      updatedAt: '2026-04-01T00:00:00Z',
      selectOptions: {},
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports/1']}>
          <Routes>
            <Route path="/reports/:id" element={<ReportDetail />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByPlaceholderText('Choose campus')).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /how to read this report/i }),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /collapse parameters/i }));

    expect(screen.queryByPlaceholderText('Choose campus')).not.toBeInTheDocument();
    expect(screen.getByText(/parameters are hidden/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /show parameters/i }));

    expect(screen.getByPlaceholderText('Choose campus')).toBeInTheDocument();
  });

  it('shows report help when the detail includes help text', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    });

    const { getReport } = await import('../api/client');
    vi.mocked(getReport).mockResolvedValue({
      id: 4,
      slug: 'budget-year-fund-report',
      name: 'Budget Year Fund Report',
      description: 'Shows fund balances for a selected budget year.',
      helpText: 'Available Budget includes calculated current encumbrances.',
      category: 'finance',
      sqlTemplate: 'select 1',
      parameters: [],
      defaultLimit: 10000,
      isActive: true,
      createdBy: 'manual',
      createdAt: '2026-04-01T00:00:00Z',
      updatedAt: '2026-04-01T00:00:00Z',
      selectOptions: {},
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports/4']}>
          <Routes>
            <Route path="/reports/:id" element={<ReportDetail />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(
      await screen.findByRole('button', { name: /how to read this report/i }),
    ).toBeInTheDocument();
  });

  it('does not show report help when help text contains only whitespace', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    });

    const { getReport } = await import('../api/client');
    vi.mocked(getReport).mockResolvedValue({
      id: 4,
      slug: 'budget-year-fund-report',
      name: 'Budget Year Fund Report',
      description: 'Shows fund balances for a selected budget year.',
      helpText: '  \n\t  ',
      category: 'finance',
      sqlTemplate: 'select 1',
      parameters: [],
      defaultLimit: 10000,
      isActive: true,
      createdBy: 'manual',
      createdAt: '2026-04-01T00:00:00Z',
      updatedAt: '2026-04-01T00:00:00Z',
      selectOptions: {},
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports/4']}>
          <Routes>
            <Route path="/reports/:id" element={<ReportDetail />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Budget Year Fund Report' }))
      .toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /how to read this report/i }),
    ).not.toBeInTheDocument();
  });

  it('offers a FOLIO UUID export only when the report advertises the capability', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    });
    const { getReport, runReport } = await import('../api/client');
    vi.mocked(getReport).mockResolvedValue({
      id: 8,
      slug: 'marc-bibliographic-records-missing-tag',
      name: 'MARC Bibliographic Records Missing a Tag',
      description: 'Finds records missing a MARC tag.',
      category: 'cataloging',
      sqlTemplate: 'select 1',
      parameters: [],
      defaultLimit: 100000,
      isActive: true,
      createdBy: 'manual',
      createdAt: '2026-08-06T00:00:00Z',
      updatedAt: '2026-08-06T00:00:00Z',
      selectOptions: {},
      identifierExportAvailable: true,
    });
    vi.mocked(runReport).mockResolvedValue({
      jobId: 'job-8',
      status: 'pending',
      reportName: 'MARC Bibliographic Records Missing a Tag',
      outputMode: 'file',
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports/8']}>
          <Routes>
            <Route path="/reports/:id" element={<ReportDetail />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('button', { name: 'Run Report' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Export CSV' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Export FOLIO UUID list' }));

    await waitFor(() => {
      expect(runReport).toHaveBeenCalledWith(8, {}, {
        outputMode: 'file',
        exportKind: 'identifier',
      });
    });
  });

  it('does not offer a FOLIO UUID export without the report capability', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    });
    const { getReport } = await import('../api/client');
    vi.mocked(getReport).mockResolvedValue({
      id: 1,
      slug: 'budget-report',
      name: 'Budget Report by Material Type',
      description: 'Summarizes expenditures by material type.',
      category: 'acquisitions',
      sqlTemplate: 'select 1',
      parameters: [],
      defaultLimit: 10000,
      isActive: true,
      createdBy: 'manual',
      createdAt: '2026-04-01T00:00:00Z',
      updatedAt: '2026-04-01T00:00:00Z',
      selectOptions: {},
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports/1']}>
          <Routes>
            <Route path="/reports/:id" element={<ReportDetail />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('button', { name: 'Run Report' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Export FOLIO UUID list' })).not.toBeInTheDocument();
  });

  it('uses the specialized MARC finder panel, validates before submit, and preserves string params', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { getReport, runReport } = await import('../api/client');
    const locationId = '11111111-1111-4111-8111-111111111111';
    vi.mocked(getReport).mockResolvedValue({
      id: 9,
      slug: 'marc-field-indicator-content-finder',
      name: 'MARC Field, Indicator, and Content Finder',
      description: 'Finds present or missing MARC field rows.',
      category: 'cataloging',
      sqlTemplate: 'select 1',
      parameters: [
        { name: 'locationIds', type: 'multiselect', label: 'Locations', required: true, default: '', resolvedDefault: '', max_selections: 100 },
        { name: 'locationBasis', type: 'select', label: 'Location basis', required: true, default: 'effective_item', resolvedDefault: 'effective_item' },
        { name: 'marcTag', type: 'text', label: 'MARC tag', required: true, default: '', resolvedDefault: '' },
        { name: 'occurrenceCondition', type: 'select', label: 'Occurrence condition', required: true, default: 'has', resolvedDefault: 'has' },
        { name: 'firstIndicator', type: 'select', label: 'First indicator', required: true, default: 'any', resolvedDefault: 'any' },
        { name: 'secondIndicator', type: 'select', label: 'Second indicator', required: true, default: 'any', resolvedDefault: 'any' },
        { name: 'subfieldCode', type: 'text', label: 'Subfield code', required: false, default: '', resolvedDefault: '' },
        { name: 'contentRule', type: 'select', label: 'Content rule', required: true, default: 'any', resolvedDefault: 'any' },
        { name: 'searchValue', type: 'text', label: 'Search text', required: false, default: '', resolvedDefault: '' },
        { name: 'caseExact', type: 'select', label: 'Case matching', required: true, default: 'false', resolvedDefault: 'false' },
      ],
      defaultLimit: 100000,
      isActive: true,
      createdBy: 'manual',
      createdAt: '2026-08-06T00:00:00Z',
      updatedAt: '2026-08-06T00:00:00Z',
      selectOptions: {
        locationIds: [{ value: locationId, label: 'Smith — SC Internet [SCINT]' }],
        locationBasis: [{ value: 'effective_item', label: 'Effective item' }, { value: 'permanent_item', label: 'Permanent item' }],
        occurrenceCondition: [{ value: 'has', label: 'Has matching occurrence' }, { value: 'missing', label: 'Missing matching occurrence' }],
        contentRule: [{ value: 'any', label: 'Any' }, { value: 'contains', label: 'Contains' }],
        caseExact: [{ value: 'false', label: 'Case-insensitive' }, { value: 'true', label: 'Case-exact' }],
      },
      identifierExportAvailable: true,
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/reports/9?rp.9.unexpected=do-not-submit']}>
          <Routes><Route path="/reports/:id" element={<ReportDetail />} /></Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const run = await screen.findByRole('button', { name: 'Run Report' });
    expect(run).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Export CSV' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Export FOLIO UUID list' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Select locations' }));
    fireEvent.click(screen.getByRole('option', { name: /Smith — SC Internet/ }));
    fireEvent.change(screen.getByLabelText(/MARC tag/), { target: { value: '035' } });
    expect(screen.getByLabelText('MARC finder interpretation')).toHaveTextContent('tag 035');
    fireEvent.change(screen.getByLabelText(/Content rule/), { target: { value: 'contains' } });
    fireEvent.change(screen.getByLabelText('Search text'), { target: { value: '(SCTFEBA)' } });
    fireEvent.change(screen.getByLabelText(/Case matching/), { target: { value: 'true' } });
    expect(run).not.toBeDisabled();

    vi.mocked(runReport).mockResolvedValueOnce({ jobId: 'job-9', status: 'pending', reportName: 'MARC Field, Indicator, and Content Finder', outputMode: 'file' });
    fireEvent.click(run);
    await waitFor(() => expect(runReport).toHaveBeenCalledWith(9, {
      locationIds: locationId,
      locationBasis: 'effective_item',
      marcTag: '035',
      occurrenceCondition: 'has',
      firstIndicator: 'any',
      secondIndicator: 'any',
      subfieldCode: '',
      contentRule: 'contains',
      searchValue: '(SCTFEBA)',
      caseExact: 'true',
    }, { outputMode: 'table' }));
    expect(vi.mocked(runReport).mock.calls[vi.mocked(runReport).mock.calls.length - 1]?.[1]).not.toHaveProperty('unexpected');
  });

  it('places API field errors beside finder controls and clears them on edit', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { getReport, runReport } = await import('../api/client');
    const locationId = '11111111-1111-4111-8111-111111111111';
    vi.mocked(getReport).mockResolvedValue({
      id: 9, slug: 'marc-field-indicator-content-finder', name: 'MARC Field, Indicator, and Content Finder',
      description: 'Finds MARC rows.', category: 'cataloging', sqlTemplate: 'select 1', parameters: [
        { name: 'locationIds', type: 'multiselect', label: 'Locations', required: true, default: '', resolvedDefault: '', max_selections: 100 },
        { name: 'locationBasis', type: 'select', label: 'Location basis', required: true, default: 'effective_item', resolvedDefault: 'effective_item' },
        { name: 'marcTag', type: 'text', label: 'MARC tag', required: true, default: '', resolvedDefault: '' },
        { name: 'occurrenceCondition', type: 'select', label: 'Occurrence condition', required: true, default: 'has', resolvedDefault: 'has' },
        { name: 'firstIndicator', type: 'select', label: 'First indicator', required: true, default: 'any', resolvedDefault: 'any' },
        { name: 'secondIndicator', type: 'select', label: 'Second indicator', required: true, default: 'any', resolvedDefault: 'any' },
        { name: 'subfieldCode', type: 'text', label: 'Subfield code', required: false, default: '', resolvedDefault: '' },
        { name: 'contentRule', type: 'select', label: 'Content rule', required: true, default: 'any', resolvedDefault: 'any' },
        { name: 'searchValue', type: 'text', label: 'Search text', required: false, default: '', resolvedDefault: '' },
        { name: 'caseExact', type: 'select', label: 'Case matching', required: true, default: 'false', resolvedDefault: 'false' },
      ],
      defaultLimit: 100000, isActive: true, createdBy: 'manual', createdAt: '2026-08-06T00:00:00Z', updatedAt: '2026-08-06T00:00:00Z',
      selectOptions: {
        locationIds: [{ value: locationId, label: 'Smith — SC Internet [SCINT]' }],
        locationBasis: [{ value: 'effective_item', label: 'Effective item' }],
        occurrenceCondition: [{ value: 'has', label: 'Has matching occurrence' }],
        contentRule: [{ value: 'contains', label: 'Contains' }],
        caseExact: [{ value: 'false', label: 'Case-insensitive' }],
      }, identifierExportAvailable: true,
    });
    const error = new axios.AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
      status: 400,
      statusText: 'Bad Request',
      headers: {},
      config: { headers: new axios.AxiosHeaders() },
      data: { error: 'Report parameters are invalid.', fieldErrors: { searchValue: 'Search text is required.' } },
    });
    vi.mocked(runReport).mockRejectedValueOnce(error);

    render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[`/reports/9?rp.9.locationIds=${locationId}&rp.9.locationBasis=effective_item&rp.9.marcTag=035&rp.9.occurrenceCondition=has&rp.9.firstIndicator=any&rp.9.secondIndicator=any&rp.9.contentRule=contains&rp.9.searchValue=x&rp.9.caseExact=false`]}><Routes><Route path="/reports/:id" element={<ReportDetail />} /></Routes></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Run Report' }));
    expect(await screen.findByText('Search text is required.')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Search text'), { target: { value: 'updated' } });
    await waitFor(() => expect(screen.queryByText('Search text is required.')).not.toBeInTheDocument());
    expect(screen.queryByText('Submit error: Report parameters are invalid.')).not.toBeInTheDocument();
  });

  it('keeps both governed export actions available and sends file requests', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { runReport } = await import('../api/client');
    const locationId = '11111111-1111-4111-8111-111111111111';
    vi.mocked(runReport).mockReset();
    vi.mocked(runReport).mockResolvedValue({ jobId: 'job-export', status: 'pending', reportName: 'MARC Field, Indicator, and Content Finder', outputMode: 'file' });
    // The full finder detail is supplied by the preceding finder workflow test and remains the same API contract.
    const entry = `/reports/9?rp.9.locationIds=${locationId}&rp.9.locationBasis=effective_item&rp.9.marcTag=035&rp.9.occurrenceCondition=has&rp.9.firstIndicator=any&rp.9.secondIndicator=any&rp.9.contentRule=contains&rp.9.searchValue=x&rp.9.caseExact=false`;

    render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[entry]}><Routes><Route path="/reports/:id" element={<ReportDetail />} /></Routes></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Export CSV' }));
    await waitFor(() => expect(runReport).toHaveBeenCalledWith(9, expect.objectContaining({ locationIds: locationId }), { outputMode: 'file', exportKind: 'worklist' }));

    cleanup();
    vi.mocked(runReport).mockClear();
    render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[entry]}><Routes><Route path="/reports/:id" element={<ReportDetail />} /></Routes></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: 'Export FOLIO UUID list' }));
    await waitFor(() => expect(runReport).toHaveBeenCalledWith(9, expect.objectContaining({ locationIds: locationId }), { outputMode: 'file', exportKind: 'identifier' }));
  });

  it('does not autorun invalid finder URLs but does autorun a valid finder URL', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { runReport } = await import('../api/client');
    const locationId = '11111111-1111-4111-8111-111111111111';
    vi.mocked(runReport).mockReset();
    vi.mocked(runReport).mockResolvedValue({ jobId: 'job-autorun', status: 'pending', reportName: 'MARC Field, Indicator, and Content Finder', outputMode: 'file' });
    const base = `rp.9.locationIds=${locationId}&rp.9.locationBasis=effective_item&rp.9.marcTag=035&rp.9.occurrenceCondition=has&rp.9.firstIndicator=any&rp.9.secondIndicator=any&rp.9.contentRule=contains&rp.9.searchValue=x&rp.9.caseExact=false`;

    render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[`/reports/9?autorun=9&${base.replace('rp.9.searchValue=x', 'rp.9.searchValue=')}`]}><Routes><Route path="/reports/:id" element={<ReportDetail />} /></Routes></MemoryRouter></QueryClientProvider>);
    expect(await screen.findByRole('button', { name: 'Run Report' })).toBeDisabled();
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(runReport).not.toHaveBeenCalled();

    cleanup();
    render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[`/reports/9?autorun=9&${base}`]}><Routes><Route path="/reports/:id" element={<ReportDetail />} /></Routes></MemoryRouter></QueryClientProvider>);
    await waitFor(() => expect(runReport).toHaveBeenCalledWith(9, expect.objectContaining({ marcTag: '035' }), { outputMode: 'table' }));
  });
});
