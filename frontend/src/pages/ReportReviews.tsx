import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  Ban,
  CheckCircle2,
  ChevronRight,
  ClipboardCheck,
  FileWarning,
  Database,
  Loader2,
  RefreshCw,
  ShieldAlert,
} from 'lucide-react';
import {
  claimReportReview,
  clearQueryMemorySuppression,
  fetchQueryMemory,
  fetchReportReview,
  fetchReportReviews,
  updateReportReview,
  updateQueryMemoryReuseApproval,
} from '../api/client';
import type {
  ReportReviewAdvisoryState,
  ReportReviewDetail,
  ReportReviewDisposition,
  ReportReviewFilters,
  ReportReviewStatus,
  ReportReviewUpdate,
  QueryMemoryStatus,
} from '../types';
import { fmtDate } from '../utils/format';

const STATUS_OPTIONS: Array<{ value: ReportReviewStatus; label: string }> = [
  { value: 'pending', label: 'Pending' },
  { value: 'in_review', label: 'In review' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'dismissed', label: 'Dismissed' },
];

const DISPOSITIONS: Array<{ value: ReportReviewDisposition; label: string }> = [
  { value: 'acceptable', label: 'Acceptable' },
  { value: 'assumption_change', label: 'Assumption change' },
  { value: 'deterministic_candidate', label: 'Deterministic candidate' },
  { value: 'generation_defect', label: 'Generation defect' },
  { value: 'data_unavailable', label: 'Data unavailable' },
  { value: 'specialist_interpretation', label: 'Specialist interpretation' },
];

function extractApiError(error: unknown, fallback: string) {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const response = (error as { response?: { data?: { error?: string } } }).response;
    if (response?.data?.error) return response.data.error;
  }
  return fallback;
}

function statusClass(status: ReportReviewStatus) {
  if (status === 'pending') return 'bg-amber-100 text-amber-800 border-amber-200';
  if (status === 'in_review') return 'bg-blue-100 text-blue-800 border-blue-200';
  if (status === 'resolved') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
  return 'bg-gray-100 text-gray-700 border-gray-200';
}

function advisoryClass(state: ReportReviewAdvisoryState) {
  return state === 'superseded'
    ? 'bg-red-50 text-red-700 border-red-200'
    : 'bg-amber-50 text-amber-800 border-amber-200';
}

function JsonEvidence({ label, value }: { label: string; value: unknown }) {
  if (value === null || (typeof value === 'object' && Object.keys(value as object).length === 0)) return null;
  return (
    <div>
      <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">{label}</h4>
      <pre className="max-h-56 overflow-auto rounded border border-slate-200 bg-slate-950 p-3 text-xs leading-5 text-slate-100">
        {JSON.stringify(value, null, 2)}
      </pre>
    </div>
  );
}

function QueryMemoryPanel() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<QueryMemoryStatus>('all');
  const [actionError, setActionError] = useState<string | null>(null);
  const memory = useQuery({
    queryKey: ['query-memory', status],
    queryFn: () => fetchQueryMemory(status),
  });
  const approval = useMutation({
    mutationFn: ({ id, approved }: { id: number; approved: boolean }) =>
      updateQueryMemoryReuseApproval(id, approved),
    onMutate: () => setActionError(null),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['query-memory'] }),
    onError: (error) => setActionError(extractApiError(error, 'Unable to update reuse approval.')),
  });
  const suppression = useMutation({
    mutationFn: (id: number) => clearQueryMemorySuppression(id),
    onMutate: () => setActionError(null),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['query-memory'] }),
    onError: (error) => setActionError(extractApiError(error, 'Unable to clear suppression.')),
  });

  const confirmApproval = (id: number, approved: boolean) => {
    const message = approved
      ? 'Approve this AI-built query for compatible cross-user reuse? Its provenance will remain AI-built.'
      : 'Revoke cross-user reuse approval for this query?';
    if (window.confirm(message)) approval.mutate({ id, approved });
  };

  const confirmSuppressionClear = (id: number) => {
    if (window.confirm('Clear suppression for every exact SQL, schema-version, and scope match? This does not approve reuse.')) {
      suppression.mutate(id);
    }
  };

  return (
    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="flex items-center gap-2 font-semibold text-slate-900"><Database size={17} className="text-folio-600" /> Query memory</h2>
          <p className="mt-1 text-sm text-slate-500">Review explicit feedback before AI-built SQL can be reused across users.</p>
        </div>
        <label className="text-sm font-medium text-slate-700">
          Show
          <select
            aria-label="Query memory status"
            value={status}
            onChange={(event) => setStatus(event.target.value as QueryMemoryStatus)}
            className="ml-2 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm"
          >
            <option value="all">All decisions</option>
            <option value="accurate">Accurate</option>
            <option value="suppressed">Suppressed</option>
            <option value="approved">Approved</option>
          </select>
        </label>
      </div>
      {actionError && <div role="alert" className="m-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">{actionError}</div>}
      {memory.isLoading ? (
        <div className="flex justify-center py-16 text-folio-600"><Loader2 className="animate-spin" /></div>
      ) : memory.isError ? (
        <div className="m-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">Unable to load query memory.</div>
      ) : memory.data?.items.length === 0 ? (
        <div className="px-5 py-14 text-center text-sm text-slate-500">No query-memory records match this filter.</div>
      ) : (
        <div className="divide-y divide-slate-100">
          {memory.data?.items.map((item) => (
            <article key={item.id} className="grid gap-4 px-5 py-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
              <div className="min-w-0">
                <h3 className="font-semibold leading-6 text-slate-900">{item.question}</h3>
                <div className="mt-2 flex flex-wrap gap-1.5 text-xs font-medium">
                  <span className="rounded border border-sky-200 bg-sky-50 px-2 py-0.5 text-sky-800">AI-built</span>
                  <span className={`rounded border px-2 py-0.5 ${item.resultAccuracy === 'accurate' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : item.resultAccuracy === 'inaccurate' ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-700'}`}>{item.resultAccuracy}</span>
                  {item.reuseSuppressed && <span className="rounded border border-red-200 bg-red-50 px-2 py-0.5 text-red-700">suppressed</span>}
                  {item.adminReuseApprovedAt && <span className="rounded border border-teal-200 bg-teal-50 px-2 py-0.5 text-teal-800">cross-user approved</span>}
                </div>
                <dl className="mt-3 grid gap-x-6 gap-y-2 text-xs text-slate-600 sm:grid-cols-2 xl:grid-cols-4">
                  <div><dt className="font-semibold text-slate-500">SQL hash</dt><dd className="truncate font-mono" title={item.sqlHash}>{item.sqlHash.slice(0, 16)}…</dd></div>
                  <div><dt className="font-semibold text-slate-500">Strict schema</dt><dd>{item.strictSchemaCompatible ? 'Compatible' : 'Stale'}</dd></div>
                  <div><dt className="font-semibold text-slate-500">Global schema</dt><dd>{item.globalSchemaCompatible ? 'Compatible' : 'Stale'}</dd></div>
                  <div><dt className="font-semibold text-slate-500">Scope</dt><dd>{item.scopeCompatible ? 'Recorded' : 'Unavailable'}</dd></div>
                </dl>
              </div>
              <div className="flex flex-wrap gap-2 lg:max-w-64 lg:justify-end">
                {item.adminReuseApprovedAt ? (
                  <button type="button" onClick={() => confirmApproval(item.id, false)} disabled={approval.isPending} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60">Revoke reuse approval</button>
                ) : item.approvalEligible ? (
                  <button type="button" onClick={() => confirmApproval(item.id, true)} disabled={approval.isPending} className="rounded-md bg-folio-600 px-3 py-2 text-sm font-semibold text-white hover:bg-folio-700 disabled:opacity-60">Approve for cross-user reuse</button>
                ) : null}
                {item.reuseSuppressed && (
                  <button type="button" onClick={() => confirmSuppressionClear(item.id)} disabled={suppression.isPending} className="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-60"><Ban size={14} /> Clear suppression after review</button>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}

export default function ReportReviews() {
  const queryClient = useQueryClient();
  const [filters, setFilters] = useState<ReportReviewFilters>({ status: 'pending', disposition: '', limit: 25, offset: 0 });
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detailOverride, setDetailOverride] = useState<ReportReviewDetail | null>(null);
  const [disposition, setDisposition] = useState<ReportReviewDisposition | ''>('');
  const [advisoryState, setAdvisoryState] = useState<ReportReviewAdvisoryState>('none');
  const [notes, setNotes] = useState('');
  const [supersededByJobId, setSupersededByJobId] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'reviews' | 'memory'>('reviews');

  const reviewList = useQuery({
    queryKey: ['report-reviews', filters],
    queryFn: () => fetchReportReviews(filters),
  });
  const reviewDetail = useQuery({
    queryKey: ['report-review', selectedId],
    queryFn: () => fetchReportReview(selectedId!),
    enabled: selectedId !== null,
  });

  const detail = detailOverride ?? reviewDetail.data;

  const invalidateReviewQueries = async (id: string) => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['report-reviews'] }),
      queryClient.invalidateQueries({ queryKey: ['report-review', id] }),
    ]);
  };

  const claimMutation = useMutation({
    mutationFn: claimReportReview,
    onMutate: () => setActionError(null),
    onSuccess: async (updated) => {
      setDetailOverride(updated);
      await invalidateReviewQueries(updated.id);
    },
    onError: async (error, id) => {
      setActionError(extractApiError(error, 'Unable to claim this review.'));
      await invalidateReviewQueries(id);
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, input }: { id: string; input: ReportReviewUpdate }) => updateReportReview(id, input),
    onMutate: () => setActionError(null),
    onSuccess: async (updated) => {
      setDetailOverride(updated);
      await invalidateReviewQueries(updated.id);
    },
    onError: async (error, variables) => {
      setActionError(extractApiError(error, 'Unable to update this review.'));
      await invalidateReviewQueries(variables.id);
    },
  });

  const queueLabel = useMemo(
    () => `${reviewList.data?.pagination.total ?? 0} ${filters.status === 'pending' ? 'pending' : filters.status.replace('_', ' ')}`,
    [filters.status, reviewList.data?.pagination.total],
  );

  const openReview = (id: string) => {
    setSelectedId(id);
    setDetailOverride(null);
    setDisposition('');
    setAdvisoryState('none');
    setNotes('');
    setSupersededByJobId('');
    setActionError(null);
  };

  const submitUpdate = (status: 'resolved' | 'dismissed') => {
    if (!detail) return;
    if (!disposition) {
      setActionError(`Choose a disposition before ${status === 'resolved' ? 'resolving' : 'dismissing'} this review.`);
      return;
    }
    if (status === 'resolved' && advisoryState === 'superseded' && !supersededByJobId.trim()) {
      setActionError('Enter the completed replacement job ID before superseding this report.');
      return;
    }

    updateMutation.mutate({
      id: detail.id,
      input: {
        status,
        disposition,
        notes: notes.trim(),
        advisoryState: status === 'resolved' ? advisoryState : 'none',
        ...(status === 'resolved' && advisoryState === 'superseded'
          ? { supersededByJobId: supersededByJobId.trim() }
          : {}),
      },
    });
  };

  return (
    <div className="max-w-screen-2xl mx-auto p-4 sm:p-6">
      <div className="mb-6 flex flex-col gap-4 border-b border-slate-200 pb-5 lg:flex-row lg:items-end lg:justify-between">
        <div className="flex items-start gap-3">
          <div className="rounded-lg bg-amber-100 p-2.5 text-amber-700"><ClipboardCheck size={23} /></div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">Administrator workspace</p>
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">AI Report Review</h1>
            <p className="mt-1 text-sm text-slate-500">Investigate reports flagged for an administrator decision. Review state is advisory and never changes job execution.</p>
          </div>
        </div>
        <div className="flex items-center gap-2 text-sm">
          <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 font-medium text-amber-800">{queueLabel}</span>
          <button
            type="button"
            onClick={() => reviewList.refetch()}
            className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-700 shadow-sm hover:bg-gray-50"
          >
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      <div className="mb-4 inline-flex rounded-lg border border-slate-200 bg-slate-100 p-1" role="tablist" aria-label="Administrator AI tools">
        <button type="button" role="tab" aria-selected={activeTab === 'reviews'} onClick={() => setActiveTab('reviews')} className={`rounded-md px-3 py-1.5 text-sm font-semibold transition ${activeTab === 'reviews' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}>Report reviews</button>
        <button type="button" role="tab" aria-selected={activeTab === 'memory'} onClick={() => setActiveTab('memory')} className={`rounded-md px-3 py-1.5 text-sm font-semibold transition ${activeTab === 'memory' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}>Query memory</button>
      </div>

      {activeTab === 'reviews' ? (
        <>

      <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
        <label className="text-sm font-medium text-slate-700">
          Queue status
          <select
            value={filters.status}
            onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value as ReportReviewStatus, offset: 0 }))}
            className="ml-2 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm"
          >
            {STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </label>
        <label className="text-sm font-medium text-slate-700">
          Filter disposition
          <select
            value={filters.disposition ?? ''}
            onChange={(event) => setFilters((current) => ({ ...current, disposition: event.target.value as ReportReviewDisposition | '', offset: 0 }))}
            className="ml-2 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm"
          >
            <option value="">All dispositions</option>
            {DISPOSITIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </label>
      </div>

      <div className="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(430px,1.1fr)]">
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Review queue</div>
          {reviewList.isLoading ? (
            <div className="flex justify-center py-16 text-folio-600"><Loader2 className="animate-spin" /></div>
          ) : reviewList.isError ? (
            <div className="m-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">Unable to load report reviews.</div>
          ) : reviewList.data?.items.length === 0 ? (
            <div className="px-5 py-14 text-center text-sm text-slate-500">No reviews match these filters.</div>
          ) : (
            <ul className="divide-y divide-slate-100">
              {reviewList.data?.items.map((review) => (
                <li key={review.id}>
                  <button
                    type="button"
                    aria-label={`Open review: ${review.question}`}
                    onClick={() => openReview(review.id)}
                    className={`w-full px-4 py-4 text-left transition-colors hover:bg-slate-50 ${selectedId === review.id ? 'bg-amber-50/70 ring-1 ring-inset ring-amber-200' : ''}`}
                  >
                    <div className="flex items-start gap-3">
                      <FileWarning size={17} className="mt-0.5 flex-none text-amber-600" />
                      <div className="min-w-0 flex-1">
                        <div className="line-clamp-2 font-medium leading-5 text-slate-900">{review.question}</div>
                        <div className="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                          <span className={`rounded border px-1.5 py-0.5 font-medium ${statusClass(review.status)}`}>{review.status.replace('_', ' ')}</span>
                          {review.advisoryState !== 'none' && <span className={`rounded border px-1.5 py-0.5 font-medium ${advisoryClass(review.advisoryState)}`}>{review.advisoryState}</span>}
                          <span className="text-slate-400">Created {fmtDate(review.createdAt)}</span>
                        </div>
                      </div>
                      <ChevronRight size={17} className="mt-0.5 flex-none text-slate-400" />
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="min-h-[32rem] rounded-lg border border-slate-200 bg-white shadow-sm">
          {!selectedId ? (
            <div className="flex h-full min-h-[32rem] flex-col items-center justify-center px-8 text-center text-slate-500">
              <ShieldAlert size={28} className="mb-3 text-amber-500" />
              <h2 className="font-semibold text-slate-700">Select a report to review</h2>
              <p className="mt-1 max-w-sm text-sm">Technical evidence and report actions appear here only after a queue item is opened.</p>
            </div>
          ) : reviewDetail.isLoading && !detail ? (
            <div className="flex min-h-[32rem] items-center justify-center text-folio-600"><Loader2 className="animate-spin" /></div>
          ) : detail ? (
            <div>
              <div className="border-b border-slate-200 bg-slate-50 px-5 py-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-amber-700">Review detail</p>
                    <h2 className="mt-1 text-lg font-semibold leading-6 text-slate-900">{detail.question}</h2>
                  </div>
                  <span className={`shrink-0 rounded border px-2 py-1 text-xs font-semibold ${statusClass(detail.status)}`}>{detail.status.replace('_', ' ')}</span>
                </div>
              </div>

              <div className="space-y-5 p-5">
                {actionError && <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">{actionError}</div>}
                <div className="grid gap-3 sm:grid-cols-2 text-sm">
                  <div><span className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Created</span>{fmtDate(detail.createdAt)}</div>
                  <div><span className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Linked job</span>{detail.queryJobId ?? 'Not linked'}</div>
                </div>

                {detail.status === 'pending' && (
                  <button
                    type="button"
                    onClick={() => claimMutation.mutate(detail.id)}
                    disabled={claimMutation.isPending}
                    className="inline-flex items-center gap-2 rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 disabled:opacity-60"
                  >
                    {claimMutation.isPending ? <Loader2 size={15} className="animate-spin" /> : <ClipboardCheck size={15} />} Claim review
                  </button>
                )}

                {detail.status === 'in_review' && (
                  <div className="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-800"><CheckCircle2 size={16} className="text-folio-600" /> Record a decision</div>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <label className="text-sm font-medium text-slate-700">Disposition
                        <select aria-label="Disposition" value={disposition} onChange={(event) => setDisposition(event.target.value as ReportReviewDisposition | '')} className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm">
                          <option value="">Choose a disposition</option>
                          {DISPOSITIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                      </label>
                      <label className="text-sm font-medium text-slate-700">Report advisory
                        <select aria-label="Report advisory" value={advisoryState} onChange={(event) => setAdvisoryState(event.target.value as ReportReviewAdvisoryState)} className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm">
                          <option value="none">No advisory</option>
                          <option value="cautioned">Add a caution</option>
                          <option value="superseded">Supersede this report</option>
                        </select>
                      </label>
                    </div>
                    {advisoryState === 'superseded' && (
                      <label className="block text-sm font-medium text-slate-700">Replacement job ID
                        <input value={supersededByJobId} onChange={(event) => setSupersededByJobId(event.target.value)} className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm" placeholder="Completed replacement job ID" />
                      </label>
                    )}
                    <label className="block text-sm font-medium text-slate-700">Administrator notes
                      <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={3} className="mt-1 block w-full resize-y rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm" placeholder="Record the review rationale" />
                    </label>
                    <div className="flex flex-wrap gap-2">
                      <button type="button" onClick={() => submitUpdate('resolved')} disabled={updateMutation.isPending} className="rounded-md bg-folio-600 px-3 py-2 text-sm font-semibold text-white hover:bg-folio-700 disabled:opacity-60">Resolve review</button>
                      <button type="button" onClick={() => submitUpdate('dismissed')} disabled={updateMutation.isPending} className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60">Dismiss review</button>
                    </div>
                  </div>
                )}

                <div className="border-t border-slate-200 pt-5">
                  <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-800"><AlertTriangle size={16} className="text-amber-600" /> Technical evidence</h3>
                  <div className="space-y-4">
                    <div>
                      <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Generated SQL</h4>
                      <pre className="max-h-56 overflow-auto rounded border border-slate-200 bg-slate-950 p-3 text-xs leading-5 text-slate-100">{detail.generatedSql ?? 'No generated SQL recorded.'}</pre>
                    </div>
                    <JsonEvidence label="Confidence evidence" value={detail.confidenceEvidence} />
                    <JsonEvidence label="Provenance" value={detail.provenance} />
                    <JsonEvidence label="Assumptions" value={detail.assumptions} />
                    <JsonEvidence label="Initial structure" value={detail.initialStructure} />
                    <JsonEvidence label="Final structure" value={detail.finalStructure} />
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div className="m-5 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">Unable to load this review.</div>
          )}
        </section>
      </div>
        </>
      ) : (
        <QueryMemoryPanel />
      )}
    </div>
  );
}
