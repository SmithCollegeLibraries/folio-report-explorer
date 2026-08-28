import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProtectedRoute from '../components/ProtectedRoute';
import type { QueryMemoryItem, ReportReviewDetail, ReportReviewSummary } from '../types';
import ReportReviews from './ReportReviews';

vi.mock('../api/client', () => ({
  fetchReportReviews: vi.fn(),
  fetchReportReview: vi.fn(),
  claimReportReview: vi.fn(),
  updateReportReview: vi.fn(),
  fetchQueryMemory: vi.fn(),
  updateQueryMemoryReuseApproval: vi.fn(),
  clearQueryMemorySuppression: vi.fn(),
}));

vi.mock('../hooks/useAuth', () => ({
  useAuth: vi.fn(),
  getShibbolethLoginUrl: () => '/admin/authorize.php',
}));

const pendingReview: ReportReviewSummary = {
  id: 'review-oldest',
  generationId: 'generation-oldest',
  status: 'pending',
  disposition: null,
  advisoryState: 'none',
  supersededByJobId: null,
  reviewedBy: null,
  claimedAt: null,
  resolvedAt: null,
  createdAt: '2026-07-20 09:00:00',
  updatedAt: '2026-07-20 09:00:00',
  question: 'How many titles were added?',
  queryJobId: 'job-original',
  userId: 7,
  executionMode: 'safe',
  route: 'ask',
  routeReason: 'report_request',
  validationStatus: 'validated',
  reviewReasons: ['ambiguous_time_range'],
};

const laterReview: ReportReviewSummary = {
  ...pendingReview,
  id: 'review-later',
  generationId: 'generation-later',
  createdAt: '2026-07-20 10:00:00',
  question: 'What did we spend on print?',
};

const reviewDetail: ReportReviewDetail = {
  ...pendingReview,
  administratorNotes: '',
  conversationId: 'conversation-1',
  parentGenerationId: null,
  followUpContext: null,
  responseMode: 'canonical',
  generatedSql: 'SELECT secret_review_sql',
  sqlHash: 'hash',
  assumptions: [],
  userNotice: null,
  confidenceEvidence: { validatorStage: 'semantic', repairAttempts: 1 },
  initialStructure: null,
  finalStructure: null,
  provenance: { model: 'trusted-model' },
  generationCreatedAt: '2026-07-20 09:00:00',
  linkedAt: '2026-07-20 09:02:00',
};

const queryMemoryItem: QueryMemoryItem = {
  id: 91,
  generationId: 'generation-memory',
  queryJobId: 'job-memory',
  question: 'Show circulation by location',
  generationProvenance: 'ai_built',
  resultAccuracy: 'accurate',
  reuseSuppressed: false,
  sqlHash: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
  dataSource: 'folio',
  strictSchemaCompatible: true,
  globalSchemaCompatible: true,
  schemaCompatible: true,
  scopeCompatible: true,
  adminReuseApprovedAt: null,
  adminReuseApprovedBy: null,
  approvalEligible: true,
  createdAt: '2026-08-27 12:00:00',
};

function renderReviews() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ReportReviews />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

async function openAndClaim() {
  const { claimReportReview } = await import('../api/client');
  vi.mocked(claimReportReview).mockResolvedValue({
    ...reviewDetail,
    status: 'in_review',
    reviewedBy: 1,
  });
  fireEvent.click(await screen.findByRole('button', { name: 'Open review: How many titles were added?' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Claim review' }));
  await screen.findByRole('button', { name: 'Resolve review' });
}

afterEach(cleanup);

beforeEach(async () => {
  vi.clearAllMocks();
  const { fetchQueryMemory, fetchReportReview, fetchReportReviews } = await import('../api/client');
  vi.mocked(fetchReportReviews).mockResolvedValue({
    items: [pendingReview, laterReview],
    pagination: { limit: 25, offset: 0, total: 2 },
  });
  vi.mocked(fetchReportReview).mockResolvedValue(reviewDetail);
  vi.mocked(fetchQueryMemory).mockResolvedValue({
    items: [queryMemoryItem],
    pagination: { limit: 25, offset: 0, total: 1 },
  });
});

describe('ReportReviews', () => {
  it('shows pending reviews oldest first and keeps technical detail closed initially', async () => {
    renderReviews();

    const oldest = await screen.findByRole('button', { name: 'Open review: How many titles were added?' });
    const later = screen.getByRole('button', { name: 'Open review: What did we spend on print?' });
    expect(oldest.compareDocumentPosition(later) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByText('SELECT secret_review_sql')).not.toBeInTheDocument();

    fireEvent.click(oldest);
    expect(await screen.findByText('SELECT secret_review_sql')).toBeInTheDocument();
    expect(screen.getByText('Technical evidence')).toBeInTheDocument();
  });

  it('refreshes the queue after an atomic claim conflict', async () => {
    const { claimReportReview, fetchReportReviews } = await import('../api/client');
    vi.mocked(claimReportReview).mockRejectedValue({
      response: { data: { error: 'Review is no longer available to claim' } },
    });
    renderReviews();

    fireEvent.click(await screen.findByRole('button', { name: 'Open review: How many titles were added?' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Claim review' }));

    expect(await screen.findByText('Review is no longer available to claim')).toBeInTheDocument();
    await waitFor(() => expect(fetchReportReviews).toHaveBeenCalledTimes(2));
  });

  it('requires a disposition before resolving a claimed review', async () => {
    renderReviews();
    await openAndClaim();

    fireEvent.click(screen.getByRole('button', { name: 'Resolve review' }));

    expect(screen.getByText('Choose a disposition before resolving this review.')).toBeInTheDocument();
  });

  it('requires a replacement job ID before superseding a report', async () => {
    renderReviews();
    await openAndClaim();

    fireEvent.change(screen.getByLabelText('Disposition'), { target: { value: 'generation_defect' } });
    fireEvent.change(screen.getByLabelText('Report advisory'), { target: { value: 'superseded' } });
    fireEvent.click(screen.getByRole('button', { name: 'Resolve review' }));

    expect(screen.getByText('Enter the completed replacement job ID before superseding this report.')).toBeInTheDocument();
  });

  it('does not render the workspace for an ordinary user on the protected route', async () => {
    const { useAuth } = await import('../hooks/useAuth');
    vi.mocked(useAuth).mockReturnValue({
      user: { role: 'user' },
      loading: false,
      authEnabled: true,
      isAdmin: false,
      accessToken: null,
      login: vi.fn(),
      logout: vi.fn(),
    } as never);

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <ProtectedRoute adminOnly><ReportReviews /></ProtectedRoute>
      </QueryClientProvider>,
    );

    expect(screen.getByRole('heading', { name: 'Access Denied' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'AI Report Review' })).not.toBeInTheDocument();
  });

  it('manages AI-built query memory separately without promoting it to Verified', async () => {
    const { clearQueryMemorySuppression, updateQueryMemoryReuseApproval } = await import('../api/client');
    vi.mocked(updateQueryMemoryReuseApproval).mockResolvedValue({
      ...queryMemoryItem,
      adminReuseApprovedAt: '2026-08-27 13:00:00',
      adminReuseApprovedBy: 1,
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderReviews();

    fireEvent.click(screen.getByRole('tab', { name: 'Query memory' }));
    expect(await screen.findByText('Show circulation by location')).toBeInTheDocument();
    expect(screen.getByText('AI-built')).toBeInTheDocument();
    expect(screen.queryByText('Verified')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Approve for cross-user reuse' }));

    await waitFor(() => expect(updateQueryMemoryReuseApproval).toHaveBeenCalledWith(91, true));
    expect(window.confirm).toHaveBeenCalled();
    expect(clearQueryMemorySuppression).not.toHaveBeenCalled();
  });

  it('keeps suppression clearing separate from reuse approval', async () => {
    const { clearQueryMemorySuppression, fetchQueryMemory, updateQueryMemoryReuseApproval } = await import('../api/client');
    vi.mocked(fetchQueryMemory).mockResolvedValue({
      items: [{ ...queryMemoryItem, resultAccuracy: 'inaccurate', reuseSuppressed: true, approvalEligible: false }],
      pagination: { limit: 25, offset: 0, total: 1 },
    });
    vi.mocked(clearQueryMemorySuppression).mockResolvedValue({
      feedback: { ...queryMemoryItem, resultAccuracy: 'inaccurate', approvalEligible: false },
      clearedCount: 1,
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderReviews();

    fireEvent.click(screen.getByRole('tab', { name: 'Query memory' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Clear suppression after review' }));

    await waitFor(() => expect(clearQueryMemorySuppression).toHaveBeenCalledWith(91));
    expect(updateQueryMemoryReuseApproval).not.toHaveBeenCalled();
  });
});
