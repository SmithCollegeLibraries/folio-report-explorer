import { useState, useRef, useEffect, type CSSProperties, type PointerEvent as ReactPointerEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { askNl, submitQuery, saveQuery, promoteToReport, submitCorrection, saveCampusPreference, downloadExportCsv, saveClarificationResolution, saveQueryFeedback, fetchQueryReuseCandidate, recordQueryReuseDecision } from '../api/client';
import { useAuth } from '../hooks/useAuth';
import { useJobPolling } from '../hooks/useJobPolling';
import SqlPreview from '../components/SqlPreview';
import ResultsTable from '../components/ResultsTable';
import ResultsModal from '../components/ResultsModal';
import { ExploratoryAssumptionsPanel } from '../components/ExploratoryAssumptionsPanel';
import { ExploratoryRecoveryPanel } from '../components/ExploratoryRecoveryPanel';
import { useToast } from '../components/ToastProvider';
import type { FollowUpContext, NlResponse, QueryReuseCandidate } from '../types';
import type { ClarificationItem, ClarificationOption, ResolverTraceEntry } from '../types/schema';
import {
  Send, Play, Copy, Sparkles, RotateCcw, Square, Loader2,
  Maximize2, Save, FileBarChart, Check, ThumbsDown, Pencil, X,
  Clock, Code2, Table2,
} from 'lucide-react';

type AskRequest = {
  question: string;
  shouldExecute?: boolean;
  includeSuggestions?: boolean;
  followUpContext?: FollowUpContext | null;
  allowExploratory?: boolean;
};

const CAMPUS_OPTIONS = [
  { code: 'ALL', name: 'All Colleges' },
  { code: 'SC',  name: 'Smith College' },
  { code: 'AC',  name: 'Amherst College' },
  { code: 'MH',  name: 'Mount Holyoke College' },
  { code: 'UM',  name: 'University Of Massachusetts' },
  { code: 'HC',  name: 'Hampshire College' },
  { code: 'RP',  name: 'Five Colleges Collections' },
  { code: 'YB',  name: 'National Yiddish Book Center' },
];

const EXAMPLE_PROMPTS = [
  'Count how many items each location has',
  'Show the top 10 most popular material types by checkout count',
  'What vendors have we spent the most money with this fiscal year?',
  'Show materials purchased in the last 90 days grouped by material type',
  'Which call number ranges have the highest circulation counts?',
];

export const ASK_RESOLVER_LOADING_STEPS = [
  'Checking known FOLIO report filters and lookup values',
  'Looking for named terms in contributors/authors, titles, identifiers, and notes',
  'Preparing a follow-up question if anything is ambiguous',
  'Automatically repairing SQL that does not pass validation',
];

export const ASK_SQL_GENERATION_LOADING_STEPS = [
  'Applying your selected clarification to the request',
  'Checking whether the request now has enough context for SQL generation',
  'Starting AI SQL generation; review the SQL and results for accuracy',
  'Automatically repairing SQL that does not pass validation',
];

export type AskProgressPhase = 'checking_request' | 'building_sql_after_clarification';

export function getAskProgressCopy(phase: AskProgressPhase): { title: string; steps: string[] } {
  if (phase === 'building_sql_after_clarification') {
    return {
      title: 'Generating and validating your query',
      steps: ASK_SQL_GENERATION_LOADING_STEPS,
    };
  }

  return {
    title: 'Generating and validating your query',
    steps: ASK_RESOLVER_LOADING_STEPS,
  };
}

const ASK_WORKSPACE_SPLIT_STORAGE_KEY = 'folio_ask_workspace_split';
const ASK_WORKSPACE_DEFAULT_SPLIT = 38;
const ASK_PANEL_WIDTH_STORAGE_KEY = 'folio_ask_panel_width';
const ASK_PANEL_DEFAULT_WIDTH = 360;

export type AskResultTabId = 'results' | 'followups' | 'sql';

export const ASK_RESULT_TABS: Array<{ id: AskResultTabId; label: string }> = [
  { id: 'results', label: 'Results' },
  { id: 'followups', label: 'Related follow-ups' },
  { id: 'sql', label: 'Output SQL' },
];

export function clampAskWorkspaceSplit(value: number): number {
  if (!Number.isFinite(value)) return ASK_WORKSPACE_DEFAULT_SPLIT;
  return Math.min(70, Math.max(30, Math.round(value)));
}

export function clampAskPanelWidth(value: number): number {
  if (!Number.isFinite(value)) return ASK_PANEL_DEFAULT_WIDTH;
  return Math.min(520, Math.max(300, Math.round(value)));
}

function getApiErrorMessage(error: unknown): string {
  if (isAxiosError(error)) {
    const data = error.response?.data as { error?: unknown; message?: unknown } | undefined;
    if (typeof data?.error === 'string' && data.error.trim()) {
      return data.error;
    }
    if (typeof data?.message === 'string' && data.message.trim()) {
      return data.message;
    }
    if (typeof error.message === 'string' && error.message.trim()) {
      return error.message;
    }
    return 'Request failed.';
  }

  if (error instanceof Error && error.message.trim()) {
    return error.message;
  }

  return 'Request failed.';
}

function isGenericAxiosStatusMessage(message: string): boolean {
  return /^Request failed with status code \d+$/i.test(message.trim());
}

function aiTimeoutMessage(): string {
  return 'The AI request timed out. Your question is fine; the model or network took too long to respond. Please try again, or simplify the request if it keeps happening.';
}

function isAxiosTimeoutError(error: unknown, message: string): boolean {
  if (!isAxiosError(error)) return false;
  const code = typeof error.code === 'string' ? error.code.toUpperCase() : '';
  return code === 'ECONNABORTED' || /timeout of \d+ms exceeded/i.test(message);
}

function isGroupedTitleValidationError(message: string): boolean {
  return /column\s+"?ii\.title"?\s+must appear in the GROUP BY clause or be used in an aggregate function/i.test(message);
}

function groupedTitleValidationMessage(): string {
  return 'Query validation failed: the generated SQL mixed title columns with grouped results incorrectly. I did not run it. Please regenerate the query; titles should come from inventory.instance__t.title and every selected non-aggregate column must be grouped.';
}

export function formatNlError(error: unknown): string {
  const message = getApiErrorMessage(error);
  if (isAxiosError(error)) {
    const data = error.response?.data as { errorType?: unknown } | undefined;
    if (data?.errorType === 'database_cancelled') {
      return message;
    }
    if (data?.errorType === 'ai_timeout') {
      return message;
    }
    if (isAxiosTimeoutError(error, message)) {
      return aiTimeoutMessage();
    }
  }
  if (isAxiosError(error) && error.response?.status === 403) {
    return `Query blocked: ${message}`;
  }
  if (isAxiosError(error) && error.response?.status === 422) {
    if (isGroupedTitleValidationError(message)) {
      return groupedTitleValidationMessage();
    }
    return `Query validation failed: ${message}`;
  }
  return `AI error: ${message}`;
}

export function formatQuerySubmitError(error: unknown): string {
  const message = getApiErrorMessage(error);

  if (isAxiosError(error)) {
    const status = error.response?.status;
    if (status === 403) {
      return `Query blocked: ${message}`;
    }
    if (status === 422) {
      if (isGroupedTitleValidationError(message)) {
        return groupedTitleValidationMessage();
      }
      return `Query validation failed: ${message}`;
    }
    if (status === 500 && isGenericAxiosStatusMessage(message)) {
      return 'Submit error: the server hit an internal error while preparing the query job. You did not do anything wrong. Please try again, and contact support if it repeats.';
    }
  }

  return `Submit error: ${message}`;
}

function isPostgresIntegerOverflow(message: string): boolean {
  return /SQLSTATE\[22003\].*out of range for type integer/i.test(message)
    || /value\s+"?\d+"?\s+is out of range for type integer/i.test(message);
}

export function formatExecutionError(error: string): string {
  if (isPostgresIntegerOverflow(error)) {
    return 'Execution error: the generated SQL tried to convert a very large value to a 32-bit integer. Regenerate the query or change the cast to BIGINT.';
  }

  return `Execution error: ${error}`;
}

type ExploratoryNoticeCopy = {
  title: string;
  message: string;
  detail?: string;
};

export function getExploratoryNoticeCopy(
  result: Pick<NlResponse, 'exploratoryNotice' | 'mode' | 'repeatabilityWarning'> | null | undefined,
): ExploratoryNoticeCopy | null {
  if (!result?.exploratoryNotice && result?.mode !== 'exploratory' && !result?.repeatabilityWarning) {
    return null;
  }

  return {
    title: result.exploratoryNotice?.title?.trim() || 'AI-assisted query',
    message: result.exploratoryNotice?.message?.trim()
      || 'I could not match this request to a verified report pattern, so I built a best-effort query. Review the results and SQL before using them.',
    detail: result.exploratoryNotice?.detail?.trim()
      || (result.mode === 'exploratory'
        ? 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.'
        : undefined),
  };
}

export function shouldShowBlockingClarification(result: NlResponse | null | undefined): boolean {
  return result?.needsClarification === true;
}

export function isExploratoryValidationHardStop(
  summary: NlResponse['validationSummary'] | null | undefined,
): boolean {
  return summary?.status === 'exhausted' || summary?.status === 'rejected';
}

function ExploratoryNoticePanel({ result }: { result: NlResponse | null }) {
  const notice = getExploratoryNoticeCopy(result);
  if (!notice) return null;

  return (
    <div className="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
      <div className="font-semibold">{notice.title}</div>
      <div className="mt-1">{notice.message}</div>
      {notice.detail && <div className="mt-1 text-xs text-sky-800">{notice.detail}</div>}
    </div>
  );
}

export function formatResolverTrace(trace: ResolverTraceEntry[] | undefined): string[] {
  if (!Array.isArray(trace)) return [];
  return trace
    .map((entry) => {
      const label = typeof entry.label === 'string' ? entry.label.trim() : '';
      if (!label) return '';
      const status = typeof entry.status === 'string' ? entry.status.trim() : '';
      const detail = typeof entry.detail === 'string' ? entry.detail.trim() : '';
      const technicalDetail = typeof entry.technicalDetail === 'string' ? entry.technicalDetail.trim() : '';
      const suffix = technicalDetail ? ` (${technicalDetail})` : '';
      if (detail) return `${label}: ${detail}${suffix}`;
      if (status === 'no_match') return `${label}: no match`;
      if (status === 'found') return `${label}${suffix}`;
      return status ? `${label}: ${status}` : label;
    })
    .filter((line): line is string => line.length > 0);
}

function buildClientFallbackSuggestions(promptText: string, campus: string): string[] {
  const prompt = promptText.toLowerCase();
  const scopeSuffix = campus !== 'All Colleges' ? ` for ${campus}` : '';

  const generic = [
    `Break this result down by month over the last 12 months${scopeSuffix}`,
    `Show the top 10 categories contributing most to this result${scopeSuffix}`,
    'Compare this metric across campuses and highlight differences',
    'List records missing key fields related to this query',
    `Show year-over-year trend changes for this metric${scopeSuffix}`,
  ];

  const circulation = [
    `Show circulation counts by material type${scopeSuffix}`,
    `Which locations have the highest and lowest circulation${scopeSuffix}`,
    `Break circulation down by patron group${scopeSuffix}`,
    `Show monthly circulation trend and peak periods${scopeSuffix}`,
  ];

  const finance = [
    `Show spending trend by fiscal year${scopeSuffix}`,
    `Which vendors account for the highest share of spending${scopeSuffix}`,
    `Break spending down by fund and expense class${scopeSuffix}`,
    'Compare encumbered versus expended amounts for the same scope',
  ];

  const inventory = [
    `Break inventory count down by library and location${scopeSuffix}`,
    'Show item age distribution for this result set',
    'Which call number ranges are most represented in this scope',
    'Show records added in the last 90 days for this same criteria',
  ];

  if (/spent|spend|budget|invoice|encumber|expend|vendor|fund|fiscal/.test(prompt)) {
    return [...finance, ...generic].slice(0, 5);
  }

  if (/loan|checkout|circulation|renew|return|call number/.test(prompt)) {
    return [...circulation, ...generic].slice(0, 5);
  }

  if (/item|holdings|instance|location|inventory|material type/.test(prompt)) {
    return [...inventory, ...generic].slice(0, 5);
  }

  return generic.slice(0, 5);
}

export function getEffectiveAskSuggestions(
  result: Pick<NlResponse, 'suggestions' | 'sql'> | null | undefined,
  promptText: string,
  campus: string,
): { suggestions: string[]; usingFallback: boolean } {
  if (!result?.sql?.trim()) {
    return { suggestions: [], usingFallback: false };
  }

  if (result.suggestions && result.suggestions.length > 0) {
    return { suggestions: result.suggestions, usingFallback: false };
  }

  return {
    suggestions: buildClientFallbackSuggestions(promptText, campus),
    usingFallback: true,
  };
}

export async function saveClarificationResolutionBestEffort(
  saveFn: (input: {
    originalQuestion: string;
    clarificationKey: string;
    clarificationBatchId?: string | null;
    term?: string | null;
    detectedTerms?: string[];
    options?: unknown[];
    selectedOptionIds?: string[];
    freeTextResponse?: string | null;
    resolvedFilter?: Record<string, unknown> | null;
    selectedSourceTable?: string | null;
    selectedSourceId?: string | null;
    selectedValue?: string | null;
    confidence?: string | null;
    promotionStatus?: string | null;
    items?: Array<{
      term?: string | null;
      clarificationKey: string;
      confidence?: string | null;
      options?: unknown[];
      selectedOptionIds?: string[];
      freeTextResponse?: string | null;
      resolvedFilter?: Record<string, unknown> | null;
      selectedSourceTable?: string | null;
      selectedSourceId?: string | null;
      selectedValue?: string | null;
      promotionStatus?: string | null;
    }>;
    generatedSql?: string | null;
    resultStatus?: string | null;
  }) => Promise<unknown>,
  input: {
    originalQuestion: string;
    clarificationKey: string;
    clarificationBatchId?: string | null;
    term?: string | null;
    detectedTerms?: string[];
    options?: unknown[];
    selectedOptionIds?: string[];
    freeTextResponse?: string | null;
    resolvedFilter?: Record<string, unknown> | null;
    selectedSourceTable?: string | null;
    selectedSourceId?: string | null;
    selectedValue?: string | null;
    confidence?: string | null;
    promotionStatus?: string | null;
    items?: Array<{
      term?: string | null;
      clarificationKey: string;
      confidence?: string | null;
      options?: unknown[];
      selectedOptionIds?: string[];
      freeTextResponse?: string | null;
      resolvedFilter?: Record<string, unknown> | null;
      selectedSourceTable?: string | null;
      selectedSourceId?: string | null;
      selectedValue?: string | null;
      promotionStatus?: string | null;
    }>;
    generatedSql?: string | null;
    resultStatus?: string | null;
  },
): Promise<boolean> {
  try {
    await saveFn(input);
    return true;
  } catch (error) {
    console.warn('Clarification telemetry save failed; continuing with clarified prompt.', error);
    return false;
  }
}

export type BatchClarificationChoice = {
  term: string;
  clarificationKey: string;
  confidence?: string;
  options?: ClarificationOption[];
  selectedOption: ClarificationOption | null;
  freeText: string;
};

function optionResolvedValue(option: ClarificationOption | null, freeText = ''): string | null {
  if (!option) return freeText.trim() || null;
  const value = option.resolvedFilter?.value;
  return typeof value === 'string' && value.trim() ? value : option.label;
}

export function buildBatchClarifiedPrompt(originalQuestion: string, choices: BatchClarificationChoice[]): string {
  const lines = choices
    .map((choice) => {
      const suffix = choice.selectedOption?.clarifiedPromptSuffix?.trim()
        || choice.freeText.trim()
        || (choice.selectedOption ? `Use ${choice.selectedOption.label} for ${choice.term}.` : '');
      return suffix ? `- ${choice.term}: ${suffix}` : '';
    })
    .filter(Boolean);

  if (lines.length === 0) return originalQuestion;
  return `${originalQuestion.trim()}\n\nClarifications:\n${lines.join('\n')}`;
}

export function buildBatchClarificationResolutionInput(
  originalQuestion: string,
  clarificationBatchId: string | undefined,
  choices: BatchClarificationChoice[],
) {
  return {
    originalQuestion,
    clarificationKey: 'batch_local_reference_resolution',
    clarificationBatchId: clarificationBatchId || null,
    items: choices.map((choice) => {
      const selectedValue = optionResolvedValue(choice.selectedOption, choice.freeText);
      const table = typeof choice.selectedOption?.resolvedFilter?.table === 'string'
        ? choice.selectedOption.resolvedFilter.table
        : null;
      const sourceId = typeof choice.selectedOption?.resolvedFilter?.sourceId === 'string'
        ? choice.selectedOption.resolvedFilter.sourceId
        : null;

      return {
        term: choice.term,
        clarificationKey: choice.clarificationKey,
        confidence: choice.confidence || null,
        options: choice.options || [],
        selectedOptionIds: choice.selectedOption ? [choice.selectedOption.id] : [],
        freeTextResponse: choice.selectedOption ? null : (choice.freeText.trim() || null),
        resolvedFilter: choice.selectedOption?.resolvedFilter || null,
        selectedSourceTable: table,
        selectedSourceId: sourceId,
        selectedValue,
        promotionStatus: 'candidate',
      };
    }),
    resultStatus: 'resolved',
  };
}

export function buildCurrentAskFollowUpContext(
  previousPrompt: string,
  result: Pick<NlResponse, 'sql' | 'assumptions'> | null | undefined,
  previousColumns: string[] = [],
): FollowUpContext | null {
  const previousSql = result?.sql?.trim();
  if (!previousSql) return null;

  return {
    source: 'ask',
    previousPrompt: previousPrompt.trim() || 'Previous Ask query',
    previousSql,
    previousColumns,
    ...(result?.assumptions?.length ? { previousAssumptions: result.assumptions } : {}),
  };
}

export function buildHistoryFollowUpContext(jobId: string): FollowUpContext {
  return {
    source: 'history',
    jobId,
  };
}

export function buildQueryFeedbackInput(
  originalQuestion: string,
  result: NlResponse,
  resultAccuracy: 'accurate' | 'inaccurate' | 'unsure',
  feedbackNote = '',
) {
  return {
    originalQuestion: originalQuestion.trim(),
    generatedSql: result.sql || null,
    route: result.route || null,
    routeReason: result.routeReason || null,
    mode: result.mode || null,
    dataSource: result.dataSource || 'folio',
    resultAccuracy,
    feedbackNote: feedbackNote.trim() || null,
  };
}

export function buildQueryReuseResolvedContext(selectedCampus: string): Record<string, string> {
  const campus = selectedCampus.trim();
  if (!campus || campus === 'All Colleges') {
    return {};
  }

  return { campus };
}

export function formatQueryReuseMatchReason(reason: string): string {
  const labels: Record<string, string> = {
    completed_successfully: 'Previous run completed successfully',
    same_data_source: 'Same data source',
    same_campus: 'Same campus or institution scope',
    same_domain: 'Same request domain',
  };

  return labels[reason] || reason.replace(/_/g, ' ');
}

export default function Ask() {
  const toast = useToast();
  const [searchParams] = useSearchParams();
  const { user, authEnabled } = useAuth();
  const [prompt, setPrompt] = useState('');
  const [nlResult, setNlResult] = useState<NlResponse | null>(null);
  const [reuseCandidate, setReuseCandidate] = useState<QueryReuseCandidate | null>(null);
  const [reusePrompt, setReusePrompt] = useState('');
  const [reuseSql, setReuseSql] = useState('');
  const [reuseCheckPending, setReuseCheckPending] = useState(false);
  const [clarificationFreeText, setClarificationFreeText] = useState('');
  const [batchClarificationChoices, setBatchClarificationChoices] = useState<Record<string, { option: ClarificationOption | null; freeText: string }>>({});
  const [followUpContext, setFollowUpContext] = useState<FollowUpContext | null>(null);
  const [followUpError, setFollowUpError] = useState<string | null>(null);
  const [activeJobId, setActiveJobId] = useState<string | null>(null);
  const [askProgressPhase, setAskProgressPhase] = useState<AskProgressPhase>('checking_request');
  const [history, setHistory] = useState<
    { prompt: string; result: NlResponse }[]
  >([]);
  const [workspaceSplit, setWorkspaceSplit] = useState<number>(() => {
    const stored = Number(localStorage.getItem(ASK_WORKSPACE_SPLIT_STORAGE_KEY));
    return clampAskWorkspaceSplit(Number.isFinite(stored) && stored > 0 ? stored : ASK_WORKSPACE_DEFAULT_SPLIT);
  });
  const [askPanelWidth, setAskPanelWidth] = useState<number>(() => {
    const stored = Number(localStorage.getItem(ASK_PANEL_WIDTH_STORAGE_KEY));
    return clampAskPanelWidth(Number.isFinite(stored) && stored > 0 ? stored : ASK_PANEL_DEFAULT_WIDTH);
  });


  // Modal state
  const [modalOpen, setModalOpen] = useState(false);

  // Save dialog state
  const [saveOpen, setSaveOpen] = useState(false);
  const [saveName, setSaveName] = useState('');
  const [saveDesc, setSaveDesc] = useState('');
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null);
  const [lastSavedId, setLastSavedId] = useState<number | null>(null);
  const [feedbackNote, setFeedbackNote] = useState('');
  const [feedbackMessage, setFeedbackMessage] = useState<string | null>(null);

  // Campus scope state — initialised from localStorage, synced with auth user preference
  const [selectedCampus, setSelectedCampus] = useState<string>(
    () => localStorage.getItem('folio_campus') ?? 'Smith College',
  );

  // When authenticated user loads/changes, adopt their saved campus preference
  useEffect(() => {
    if (user?.defaultCampus) {
      setSelectedCampus(user.defaultCampus);
      localStorage.setItem('folio_campus', user.defaultCampus);
    }
  }, [user?.defaultCampus]);

  useEffect(() => {
    const q = (searchParams.get('q') || '').trim();
    if (q) {
      setPrompt(q);
    }
    const followUpJobId = (searchParams.get('followUpJobId') || '').trim();
    if (followUpJobId) {
      setFollowUpContext(buildHistoryFollowUpContext(followUpJobId));
      setFollowUpError(null);
    }
  }, [searchParams]);

  const handleCampusChange = (campus: string) => {
    setSelectedCampus(campus);
    localStorage.setItem('folio_campus', campus);
    if (authEnabled) {
      saveCampusPreference(campus).catch(() => {});
    }
  };

  // Output preference state — persisted across sessions
  const [outputPref, setOutputPref] = useState<'preview' | 'full'>(
    () => (localStorage.getItem('folio_output_pref') as 'preview' | 'full') ?? 'preview',
  );

  const handleOutputPrefChange = (pref: 'preview' | 'full') => {
    setOutputPref(pref);
    localStorage.setItem('folio_output_pref', pref);
  };

  // Correction state
  const [correcting, setCorrecting] = useState(false);
  const [correctedSql, setCorrectedSql] = useState('');
  const [correctionNotes, setCorrectionNotes] = useState('');

  // Tab state: results-first view
  const [detailTab, setDetailTab] = useState<AskResultTabId>('results');

  // History popover state
  const [historyOpen, setHistoryOpen] = useState(false);
  const historyRef = useRef<HTMLDivElement>(null);
  const workspaceRef = useRef<HTMLDivElement>(null);

  // Close history popover on outside click
  useEffect(() => {
    if (!historyOpen) return;
    const handleClick = (e: MouseEvent) => {
      if (historyRef.current && !historyRef.current.contains(e.target as Node)) {
        setHistoryOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [historyOpen]);

  useEffect(() => {
    localStorage.setItem(ASK_WORKSPACE_SPLIT_STORAGE_KEY, String(workspaceSplit));
  }, [workspaceSplit]);

  useEffect(() => {
    localStorage.setItem(ASK_PANEL_WIDTH_STORAGE_KEY, String(askPanelWidth));
  }, [askPanelWidth]);

  const handleAskPanelResizeStart = (event: ReactPointerEvent<HTMLButtonElement>) => {
    const bounds = workspaceRef.current?.getBoundingClientRect();
    if (!bounds || bounds.width <= 0) return;
    event.currentTarget.setPointerCapture(event.pointerId);

    const handlePointerMove = (moveEvent: PointerEvent) => {
      setAskPanelWidth(clampAskPanelWidth(moveEvent.clientX - bounds.left));
    };

    const handlePointerUp = () => {
      window.removeEventListener('pointermove', handlePointerMove);
      window.removeEventListener('pointerup', handlePointerUp);
    };

    window.addEventListener('pointermove', handlePointerMove);
    window.addEventListener('pointerup', handlePointerUp);
  };

  const handleWorkspaceResizeStart = (event: ReactPointerEvent<HTMLButtonElement>) => {
    const bounds = workspaceRef.current?.getBoundingClientRect();
    if (!bounds || bounds.width <= 0) return;
    event.currentTarget.setPointerCapture(event.pointerId);

    const handlePointerMove = (moveEvent: PointerEvent) => {
      const next = ((moveEvent.clientX - bounds.left) / bounds.width) * 100;
      setWorkspaceSplit(clampAskWorkspaceSplit(next));
    };

    const handlePointerUp = () => {
      window.removeEventListener('pointermove', handlePointerMove);
      window.removeEventListener('pointerup', handlePointerUp);
    };

    window.addEventListener('pointermove', handlePointerMove);
    window.addEventListener('pointerup', handlePointerUp);
  };

  const setWorkspaceSplitPreset = (value: number) => {
    if (value === 45) {
      setAskPanelWidth(clampAskPanelWidth(460));
      return;
    }
    if (value === 30) {
      setAskPanelWidth(clampAskPanelWidth(300));
      return;
    }
    setAskPanelWidth(ASK_PANEL_DEFAULT_WIDTH);
    setWorkspaceSplit(clampAskWorkspaceSplit(value));
  };

  // --- async job polling ---
  const { job, results, isRunning, error: jobError, cancel: cancelJobFn, reset: resetJob, elapsedSeconds } = useJobPolling(activeJobId);

  const campusForRequest = selectedCampus === 'All Colleges' ? null : selectedCampus;

  const runGeneratedQuery = (
    result: NlResponse,
    questionText: string,
  ) => {
    if (!result.sql) return;
    execMut.mutate({
      sql: result.sql,
      dataSource: result.dataSource || 'folio',
      nlPrompt: questionText,
      options: {
        outputMode: outputPref === 'full' ? 'file' : 'table',
        resolvedContext: buildQueryReuseResolvedContext(selectedCampus),
      },
    });
  };

  const prependHistory = (questionText: string, result: NlResponse) => {
    setHistory((prev) => [{ prompt: questionText, result }, ...prev].slice(0, 20));
  };

  const execMut = useMutation({
    mutationFn: ({
      sql,
      dataSource,
      nlPrompt,
      options,
    }: {
      sql: string;
      dataSource?: 'folio' | 'local';
      nlPrompt?: string;
      options?: {
        outputMode?: 'table' | 'file';
        queryReuse?: {
          candidateJobId: string;
          edited: boolean;
          score?: number;
        };
        resolvedContext?: Record<string, string>;
      };
    }) => submitQuery(sql, {}, 'nl', nlPrompt || prompt.trim() || undefined, dataSource || 'folio', options),
    onSuccess: (data) => {
      if (data.jobId) {
        setActiveJobId(data.jobId);
      }
    },
    onError: (error) => {
      toast.error(formatQuerySubmitError(error));
    },
  });

  const askMut = useMutation({
    mutationFn: (request: AskRequest) =>
      askNl(
        request.question,
        campusForRequest,
        request.includeSuggestions ?? true,
        request.followUpContext ?? null,
        request.allowExploratory ?? false,
      ),
    onSuccess: (data: NlResponse, request: AskRequest) => {
      setNlResult(data);
      resetJob();
      setActiveJobId(null);
      setSaveSuccess(null);
      setLastSavedId(null);
      setFeedbackNote('');
      setFeedbackMessage(null);
      setCorrecting(false);
      setClarificationFreeText('');
      setBatchClarificationChoices({});
      setFollowUpContext(null);
      setFollowUpError(null);
      setDetailTab('results');
      prependHistory(request.question, data);

      if (request.shouldExecute !== false) {
        runGeneratedQuery(data, request.question);
      }
    },
  });

  const savedMut = useMutation({
    mutationFn: () =>
      saveQuery({
        name: saveName,
        description: saveDesc,
        queryDefinition: {},
        generatedSql: nlResult?.sql,
        source: 'nl',
        nlPrompt: history[0]?.prompt || prompt,
      }),
    onSuccess: (data) => {
      setSaveOpen(false);
      setSaveName('');
      setSaveDesc('');
      setLastSavedId(data.id);
      setSaveSuccess(`Saved as "${data.name}"`);
      setTimeout(() => setSaveSuccess(null), 4000);
    },
  });

  const promoteMut = useMutation({
    mutationFn: (id: number) => promoteToReport(id),
    onSuccess: (data) => {
      setSaveSuccess(`Promoted to report "${data.name}"`);
      setTimeout(() => setSaveSuccess(null), 4000);
    },
  });

  const correctionMut = useMutation({
    mutationFn: () =>
      submitCorrection({
        prompt: history[0]?.prompt || prompt,
        originalSql: nlResult?.sql || '',
        correctedSql,
        notes: correctionNotes || undefined,
      }),
    onSuccess: (data) => {
      setCorrecting(false);
      setCorrectedSql('');
      setCorrectionNotes('');
      setSaveSuccess(data.message);
      setTimeout(() => setSaveSuccess(null), 5000);
    },
  });

  const feedbackMut = useMutation({
    mutationFn: (resultAccuracy: 'accurate' | 'inaccurate' | 'unsure') => {
      if (!nlResult) throw new Error('No generated query is available for feedback.');
      return saveQueryFeedback(buildQueryFeedbackInput(
        history[0]?.prompt || prompt,
        nlResult,
        resultAccuracy,
        feedbackNote,
      ));
    },
    onSuccess: () => {
      setFeedbackMessage('Feedback saved');
      setFeedbackNote('');
      toast.success('Feedback saved');
      setTimeout(() => setFeedbackMessage(null), 4000);
    },
    onError: (error) => {
      toast.error(`Feedback was not saved: ${getApiErrorMessage(error)}`);
    },
  });

  useEffect(() => {
    if (jobError) {
      toast.error(formatExecutionError(jobError));
    }
  }, [jobError, toast]);

  const generateFreshSql = (question: string, context: FollowUpContext | null = followUpContext) => {
    setReuseCandidate(null);
    setReusePrompt('');
    setReuseSql('');
    setAskProgressPhase('checking_request');
    askMut.mutate({
      question,
      includeSuggestions: true,
      shouldExecute: true,
      followUpContext: context,
    });
  };

  const handleSubmit = async () => {
    const q = prompt.trim();
    if (!q) return;
    if (followUpContext?.source === 'ask' && !followUpContext.previousSql) {
      setFollowUpError('Follow-up context is missing the previous SQL. Start a new query or choose Ask follow-up again.');
      return;
    }
    setReuseCandidate(null);
    setReusePrompt('');
    setReuseSql('');
    setAskProgressPhase('checking_request');

    if (!followUpContext) {
      setReuseCheckPending(true);
      try {
        const reuse = await fetchQueryReuseCandidate({
          prompt: q,
          dataSource: 'folio',
          resolvedContext: buildQueryReuseResolvedContext(selectedCampus),
        });
        if (reuse.match) {
          setReuseCandidate(reuse.match);
          setReusePrompt(q);
          setReuseSql(reuse.match.sql);
          setNlResult(null);
          resetJob();
          setActiveJobId(null);
          setDetailTab('sql');
          return;
        }
      } catch (error) {
        toast.error(`Could not check previous successful queries: ${getApiErrorMessage(error)}`);
      } finally {
        setReuseCheckPending(false);
      }
    }

    generateFreshSql(q, followUpContext);
  };

  const handleRunReuseCandidate = () => {
    if (!reuseCandidate || !reuseSql.trim()) return;
    const result: NlResponse = {
      sql: reuseSql,
      dataSource: reuseCandidate.dataSource as 'folio' | 'local',
    };
    const originalSql = reuseCandidate.sql.trim();
    const editedSql = reuseSql.trim();
    const edited = originalSql !== editedSql;
    setNlResult(result);
    prependHistory(reusePrompt || reuseCandidate.previousPrompt, result);
    resetJob();
    setActiveJobId(null);
    setDetailTab('sql');
    execMut.mutate({
      sql: editedSql,
      dataSource: result.dataSource || 'folio',
      nlPrompt: reusePrompt || reuseCandidate.previousPrompt,
      options: {
        outputMode: outputPref === 'full' ? 'file' : 'table',
        resolvedContext: buildQueryReuseResolvedContext(selectedCampus),
        queryReuse: {
          candidateJobId: reuseCandidate.jobId,
          edited,
          score: reuseCandidate.score,
        },
      },
    });
    recordQueryReuseDecision({
      decision: edited ? 'edited' : 'accepted',
      candidateJobId: reuseCandidate.jobId,
      prompt: reusePrompt || reuseCandidate.previousPrompt,
    }).catch(() => {});
  };

  const handleGenerateFreshFromReuse = () => {
    const q = reusePrompt || prompt.trim();
    if (!q) return;
    if (reuseCandidate) {
      recordQueryReuseDecision({
        decision: 'bypassed',
        candidateJobId: reuseCandidate.jobId,
        prompt: q,
      }).catch(() => {});
    }
    generateFreshSql(q, null);
  };

  const handleCopy = () => {
    if (nlResult?.sql) {
      navigator.clipboard.writeText(nlResult.sql);
    }
  };

  const handleUseSuggestion = (suggestedPrompt: string) => {
    setPrompt(suggestedPrompt);
  };

  const handleRunSuggestion = (suggestedPrompt: string) => {
    setPrompt(suggestedPrompt);
    setAskProgressPhase('checking_request');
    askMut.mutate({
      question: suggestedPrompt,
      includeSuggestions: true,
      shouldExecute: true,
    });
  };

  const handleStartCurrentFollowUp = () => {
    const context = buildCurrentAskFollowUpContext(
      history[0]?.prompt || prompt,
      nlResult,
      results?.columns || [],
    );
    if (!context) {
      setFollowUpError('Follow-up is available after a query has generated SQL.');
      return;
    }
    setFollowUpContext(context);
    setFollowUpError(null);
    setPrompt('');
  };

  const handleCorrectAssumption = (example: string) => {
    const context = buildCurrentAskFollowUpContext(
      history[0]?.prompt || prompt,
      nlResult,
      results?.columns || [],
    );
    if (!context) {
      setFollowUpError('Assumptions can be corrected after a query has generated SQL.');
      return;
    }

    setPrompt(example);
    setAskProgressPhase('building_sql_after_clarification');
    askMut.mutate({
      question: example,
      includeSuggestions: true,
      shouldExecute: true,
      followUpContext: context,
      allowExploratory: true,
    });
  };

  const handleRetryExploratory = (question: string) => {
    const preservedQuestion = question.trim();
    if (!preservedQuestion) return;
    setPrompt(preservedQuestion);
    setAskProgressPhase('checking_request');
    askMut.mutate({
      question: preservedQuestion,
      includeSuggestions: true,
      shouldExecute: true,
      followUpContext: null,
      allowExploratory: true,
    });
  };

  const handleRefineExploratory = (question: string, suggestion: string) => {
    const originalQuestion = question.trim();
    const correction = suggestion.trim();
    if (!originalQuestion || !correction) return;
    const refinedQuestion = `${originalQuestion}\n\nCorrection: ${correction}`;
    setPrompt(refinedQuestion);
    setAskProgressPhase('checking_request');
    askMut.mutate({
      question: refinedQuestion,
      includeSuggestions: true,
      shouldExecute: true,
      followUpContext: null,
      allowExploratory: true,
    });
  };

  const handleClarificationChoice = async (option: ClarificationOption | null) => {
    if (!nlResult?.needsClarification || !nlResult.clarificationKey) return;

    const originalQuestion = history[0]?.prompt || prompt.trim();
    const freeText = option ? '' : clarificationFreeText.trim();
    if (!option && !freeText) return;
    const allowExploratory = option?.resolvedFilter?.allowExploratory === true;
    const refineExploratory = option?.resolvedFilter?.allowExploratory === false
      && nlResult.needsExploratoryApproval;

    await saveClarificationResolutionBestEffort(saveClarificationResolution, {
      originalQuestion,
      clarificationKey: nlResult.clarificationKey,
      options: nlResult.options || [],
      selectedOptionIds: option ? [option.id] : [],
      freeTextResponse: freeText || null,
      resolvedFilter: option?.resolvedFilter || null,
      resultStatus: 'resolved',
    });

    if (allowExploratory) {
      setAskProgressPhase('building_sql_after_clarification');
      askMut.mutate({
        question: originalQuestion,
        includeSuggestions: true,
        shouldExecute: true,
        allowExploratory: true,
      });
      return;
    }

    if (refineExploratory) {
      setNlResult(null);
      setPrompt(originalQuestion);
      return;
    }

    const clarifiedPrompt = option?.clarifiedPromptSuffix
      ? `${originalQuestion}\n\nClarification: ${option.clarifiedPromptSuffix}`
      : `${originalQuestion}\n\nClarification: ${freeText}`;

    setPrompt(clarifiedPrompt);
    setAskProgressPhase('building_sql_after_clarification');
    askMut.mutate({
      question: clarifiedPrompt,
      includeSuggestions: true,
      shouldExecute: true,
    });
  };

  const buildCurrentBatchChoices = (): BatchClarificationChoice[] => {
    const items = nlResult?.clarificationItems || [];
    return items.map((item) => {
      const state = batchClarificationChoices[item.clarificationKey] || { option: null, freeText: '' };
      return {
        term: item.term,
        clarificationKey: item.clarificationKey,
        confidence: item.confidence,
        options: item.options || [],
        selectedOption: state.option,
        freeText: state.freeText,
      };
    });
  };

  const allBatchItemsResolved = (): boolean => {
    const items = nlResult?.clarificationItems || [];
    return items.length > 0 && items.every((item) => {
      const state = batchClarificationChoices[item.clarificationKey];
      return !!state?.option || !!state?.freeText.trim();
    });
  };

  const handleBatchClarificationOption = (item: ClarificationItem, option: ClarificationOption) => {
    setBatchClarificationChoices((prev) => ({
      ...prev,
      [item.clarificationKey]: { option, freeText: '' },
    }));
  };

  const handleBatchClarificationFreeText = (item: ClarificationItem, freeText: string) => {
    setBatchClarificationChoices((prev) => ({
      ...prev,
      [item.clarificationKey]: { option: null, freeText },
    }));
  };

  const handleBatchClarificationContinue = async () => {
    if (!nlResult?.needsClarification || !nlResult.clarificationItems?.length || !allBatchItemsResolved()) return;

    const originalQuestion = history[0]?.prompt || prompt.trim();
    const choices = buildCurrentBatchChoices();

    await saveClarificationResolutionBestEffort(
      saveClarificationResolution,
      buildBatchClarificationResolutionInput(originalQuestion, nlResult.clarificationBatchId, choices),
    );

    const clarifiedPrompt = buildBatchClarifiedPrompt(originalQuestion, choices);
    setPrompt(clarifiedPrompt);
    setAskProgressPhase('building_sql_after_clarification');
    askMut.mutate({
      question: clarifiedPrompt,
      includeSuggestions: true,
      shouldExecute: true,
    });
  };

  const anchorPrompt = history[0]?.prompt || prompt;
  const { suggestions: effectiveSuggestions, usingFallback: usingFallbackSuggestions } = getEffectiveAskSuggestions(
    nlResult,
    anchorPrompt,
    selectedCampus,
  );

  // Are we in any loading phase?
  const isGenerating = askMut.isPending || reuseCheckPending;
  const isExecuting = execMut.isPending || isRunning;
  const isLoading = isGenerating || isExecuting;
  const hasFilePreview = !!(results?.outputMode === 'file' && results.columns.length > 0 && results.rows.length > 0);
  const showMiddlePanel = isGenerating
    || askMut.isError
    || !!followUpError
    || !!reuseCandidate
    || !!(nlResult && !isLoading && nlResult.needsClarification);
  const showRightPaneClarifications: boolean = false;
  const showRightPaneAskErrors: boolean = false;
  const askProgressCopy = getAskProgressCopy(askProgressPhase);

  return (
    <div className="h-[calc(100vh-56px)] overflow-y-auto bg-gray-50 lg:overflow-hidden">
      <div ref={workspaceRef} className="flex h-full flex-col lg:flex-row">
        <section
          className="border-b border-gray-200 bg-white lg:h-full lg:w-[var(--ask-panel-width)] lg:shrink-0 lg:overflow-y-auto lg:border-b-0 lg:border-r"
          style={{ '--ask-panel-width': `${askPanelWidth}px` } as CSSProperties}
        >
          <div className="p-4 xl:p-6">
            <div className="flex items-center gap-2 mb-3">
              <Sparkles size={20} className="text-folio-600" />
              <h2 className="text-lg font-semibold">Ask AI</h2>
            </div>

            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-500 font-medium flex-shrink-0">Scope to:</span>
                  <select
                    value={selectedCampus}
                    onChange={(e) => handleCampusChange(e.target.value)}
                    className="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:ring-2 focus:ring-folio-300 focus:border-folio-400 outline-none cursor-pointer"
                  >
                    {CAMPUS_OPTIONS.map((c) => (
                      <option key={c.code} value={c.name}>{c.name}</option>
                    ))}
                  </select>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-500 font-medium flex-shrink-0">Results:</span>
                  <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs">
                    <button
                      onClick={() => handleOutputPrefChange('preview')}
                      className={`px-3 py-1.5 transition-colors ${
                        outputPref === 'preview'
                          ? 'bg-folio-600 text-white'
                          : 'text-gray-600 hover:bg-gray-50'
                      }`}
                    >
                      Preview
                    </button>
                    <button
                      onClick={() => handleOutputPrefChange('full')}
                      className={`px-3 py-1.5 border-l border-gray-200 transition-colors ${
                        outputPref === 'full'
                          ? 'bg-folio-600 text-white'
                          : 'text-gray-600 hover:bg-gray-50'
                      }`}
                    >
                      CSV
                    </button>
                  </div>
                </div>
              </div>

              <p className="text-sm text-gray-500">
                Describe the report you need. The app checks known FOLIO terms first, then generates SQL when the request is clear.
              </p>

              {followUpContext && (
                <div className="flex items-center justify-between gap-3 border border-folio-200 bg-folio-50 rounded-lg px-3 py-2 text-sm text-folio-800">
                  <span>
                    Asking a follow-up for {followUpContext.source === 'history' ? 'a history query' : 'the current result'}
                  </span>
                  <button
                    onClick={() => {
                      setFollowUpContext(null);
                      setFollowUpError(null);
                    }}
                    className="text-xs text-folio-700 hover:text-folio-900"
                  >
                    Cancel
                  </button>
                </div>
              )}

              <div className="flex gap-2">
                <textarea
                  value={prompt}
                  onChange={(e) => setPrompt(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                      e.preventDefault();
                      handleSubmit();
                    }
                  }}
                  placeholder="Describe the report you want..."
                  className="flex-1 border rounded-lg px-4 py-3 text-sm resize-none h-28 focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
                />
                <div className="flex flex-col gap-2 self-end">
                  <button
                    onClick={handleSubmit}
                    disabled={!prompt.trim() || askMut.isPending || reuseCheckPending}
                    className="bg-folio-600 text-white px-4 py-3 rounded-lg hover:bg-folio-700 disabled:opacity-50 transition-colors"
                  >
                    {askMut.isPending || reuseCheckPending ? (
                      <RotateCcw size={18} className="animate-spin" />
                    ) : (
                      <Send size={18} />
                    )}
                  </button>
                  {history.length > 0 && (
                    <div className="relative" ref={historyRef}>
                      <button
                        onClick={() => setHistoryOpen((o) => !o)}
                        className={`w-full flex items-center justify-center px-4 py-2 rounded-lg border transition-colors ${
                          historyOpen
                            ? 'bg-folio-50 text-folio-700 border-folio-300'
                            : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50 border-gray-200'
                        }`}
                        title="Recent questions"
                      >
                        <Clock size={16} />
                      </button>
                      {historyOpen && (
                        <div className="absolute left-0 top-full mt-1 w-80 max-h-80 overflow-y-auto bg-white border border-gray-200 rounded-xl shadow-lg z-40">
                          <div className="px-3 py-2 border-b text-xs font-semibold text-gray-500 uppercase tracking-wide">
                            Recent Questions
                          </div>
                          {history.map((h, i) => (
                            <button
                              key={i}
                              onClick={() => {
                                setPrompt(h.prompt);
                                setNlResult(h.result);
                                resetJob();
                                setActiveJobId(null);
                                setDetailTab('results');
                                setHistoryOpen(false);
                              }}
                              className="block w-full text-left text-sm text-gray-600 hover:text-folio-600 hover:bg-gray-50 px-3 py-2.5 border-b border-gray-50 last:border-0"
                            >
                              <span className="line-clamp-2">{h.prompt}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>

              <div className="flex flex-wrap gap-2">
                {EXAMPLE_PROMPTS.map((ex, i) => (
                  <button
                    key={i}
                    onClick={() => {
                      setPrompt(ex);
                    }}
                    className="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1 rounded-full transition-colors"
                  >
                    {ex}
                  </button>
                ))}
              </div>
            </div>
          </div>
        </section>

        <button
          type="button"
          onPointerDown={handleAskPanelResizeStart}
          className="group hidden w-3 shrink-0 cursor-col-resize items-center justify-center bg-gray-100 hover:bg-folio-50 lg:flex"
          aria-label="Resize Ask AI panel"
          title="Drag to resize Ask AI"
        >
          <span className="h-12 w-1 rounded-full bg-gray-300 group-hover:bg-folio-500" />
        </button>

        {showMiddlePanel && (
        <section
          className="border-b border-gray-200 bg-white lg:min-h-0 lg:w-[420px] lg:shrink-0 lg:h-full lg:overflow-y-auto lg:border-b-0 lg:border-r"
          style={{ '--ask-left': `${workspaceSplit}%` } as CSSProperties}
        >
      <div className="border-b border-gray-100 px-4 py-3 xl:px-6">
        <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">Question flow</div>
        <div className="mt-1 text-sm text-gray-600">
          Follow-up questions and resolver checks appear here when the request needs clarification.
        </div>
      </div>

      {/* Success toast */}
      {saveSuccess && (
        <div className="mx-auto mt-2 max-w-3xl px-4 xl:px-6">
          <div className="flex items-center gap-2 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm text-green-700">
            <Check size={14} />
            {saveSuccess}
          </div>
        </div>
      )}

      {askMut.isError && (
        <div className="mx-auto m-4 max-w-3xl p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
          {formatNlError(askMut.error)}
        </div>
      )}
      {followUpError && (
        <div className="mx-auto m-4 max-w-3xl p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
          {followUpError}
        </div>
      )}

      {reuseCandidate && !isLoading && (
        <div className="mx-auto max-w-3xl p-4 xl:px-6">
          <div className="rounded-lg border border-folio-200 bg-folio-50 p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="text-sm font-semibold text-folio-950">
                  Previous successful query found
                </div>
                <p className="mt-1 text-sm text-folio-900">
                  A similar question has been answered successfully before. Review the SQL below, edit it if needed, or generate new SQL instead.
                </p>
              </div>
              <span className="shrink-0 rounded border border-folio-200 bg-white px-2 py-1 text-xs font-medium text-folio-700">
                {reuseCandidate.score}% match
              </span>
            </div>

            <div className="mt-4 space-y-3 text-sm">
              <div className="rounded-md border border-folio-100 bg-white px-3 py-2">
                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">Previous question</div>
                <div className="mt-1 text-gray-800">{reuseCandidate.previousPrompt}</div>
              </div>

              <div className="grid gap-2 text-xs text-gray-600 sm:grid-cols-3">
                <div className="rounded-md border border-folio-100 bg-white px-3 py-2">
                  <div className="font-semibold text-gray-500">Last run</div>
                  <div className="mt-1">{reuseCandidate.completedAt || 'Unknown'}</div>
                </div>
                <div className="rounded-md border border-folio-100 bg-white px-3 py-2">
                  <div className="font-semibold text-gray-500">Rows</div>
                  <div className="mt-1">{reuseCandidate.rowCount ?? 'Unknown'}</div>
                </div>
                <div className="rounded-md border border-folio-100 bg-white px-3 py-2">
                  <div className="font-semibold text-gray-500">Runtime</div>
                  <div className="mt-1">
                    {reuseCandidate.executionTimeMs !== null ? `${reuseCandidate.executionTimeMs} ms` : 'Unknown'}
                  </div>
                </div>
              </div>

              <div className="rounded-md border border-folio-100 bg-white px-3 py-2">
                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">Why this matched</div>
                <ul className="mt-2 space-y-1">
                  {reuseCandidate.matchReasons.map((reason) => (
                    <li key={reason} className="flex gap-2 text-xs text-gray-700">
                      <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-folio-500" />
                      <span>{formatQueryReuseMatchReason(reason)}</span>
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                <label className="text-xs font-semibold uppercase tracking-wide text-gray-500" htmlFor="query-reuse-sql">
                  SQL to rerun
                </label>
                <textarea
                  id="query-reuse-sql"
                  value={reuseSql}
                  onChange={(event) => setReuseSql(event.target.value)}
                  className="mt-1 h-48 w-full rounded-lg border border-folio-200 bg-white px-3 py-2 font-mono text-xs text-gray-800 outline-none focus:border-folio-400 focus:ring-2 focus:ring-folio-200"
                />
              </div>
            </div>

            <div className="mt-4 flex flex-wrap justify-end gap-2">
              <button
                onClick={handleGenerateFreshFromReuse}
                className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
              >
                Generate New SQL
              </button>
              <button
                onClick={handleRunReuseCandidate}
                disabled={!reuseSql.trim() || execMut.isPending}
                className="inline-flex items-center gap-2 rounded-lg bg-folio-700 px-3 py-2 text-sm font-medium text-white hover:bg-folio-800 disabled:opacity-50"
              >
                <Play size={14} />
                Run SQL
              </button>
            </div>
          </div>
        </div>
      )}

      {isGenerating && (
        <div className="mx-auto max-w-3xl p-4 xl:px-6">
          <div className="rounded-lg border border-folio-200 bg-white p-4 shadow-sm">
            <div className="flex items-start gap-3">
              <Loader2 size={24} className="animate-spin text-folio-600" />
              <div>
                <div className="text-sm font-semibold text-gray-900">
                  {askProgressCopy.title}
                </div>
                <div className="mt-2 space-y-1.5">
                  {askProgressCopy.steps.map((step) => (
                    <div key={step} className="flex items-start gap-2 text-xs text-gray-600">
                      <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-folio-500" />
                      <span>{step}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {nlResult && !isLoading && shouldShowBlockingClarification(nlResult) && nlResult.clarificationItems && nlResult.clarificationItems.length > 0 && (
        <div className="mx-auto max-w-3xl p-4 xl:px-6">
          <div className="border border-amber-200 bg-amber-50 rounded-lg p-4">
            <div className="text-sm font-semibold mb-1 text-amber-900">
              Clarification needed
            </div>
            <div className="text-sm mb-3 text-amber-900">
              {nlResult.question || 'Confirm these local terms before I generate SQL.'}
            </div>
            {nlResult.message && (
              <div className="text-sm mb-3 text-amber-900">
                {nlResult.message}
              </div>
            )}
            {formatResolverTrace(nlResult.resolverTrace).length > 0 && (
              <div className="mb-3 rounded-md border border-amber-200 bg-white px-3 py-2">
                <div className="text-xs font-semibold uppercase tracking-wide text-amber-800">
                  Resolver checks
                </div>
                <ul className="mt-1 space-y-1 text-xs text-gray-700">
                  {formatResolverTrace(nlResult.resolverTrace).map((line) => (
                    <li key={line} className="flex gap-2">
                      <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                      <span>{line}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <div className="space-y-3">
              {nlResult.clarificationItems.map((item) => {
                const state = batchClarificationChoices[item.clarificationKey] || { option: null, freeText: '' };
                return (
                  <div key={item.clarificationKey} className="bg-white border border-amber-200 rounded-lg p-3">
                    <div className="flex items-start justify-between gap-3 mb-2">
                      <div>
                        <div className="text-sm font-semibold text-gray-900">{item.term}</div>
                        <div className="text-sm text-gray-700">{item.question}</div>
                      </div>
                      {item.confidence && (
                        <span className="shrink-0 text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">
                          {item.confidence}
                        </span>
                      )}
                    </div>
                    <div className="space-y-2">
                      {(item.options || []).map((option) => {
                        const selected = state.option?.id === option.id;
                        return (
                          <button
                            key={option.id}
                            onClick={() => handleBatchClarificationOption(item, option)}
                            className={`w-full flex items-center justify-between gap-3 text-left border px-3 py-2 rounded-lg text-sm transition-colors ${
                              selected
                                ? 'bg-amber-100 border-amber-400 text-amber-950'
                                : 'bg-white border-amber-200 text-gray-800 hover:bg-amber-100'
                            }`}
                          >
                            <span>{option.label}</span>
                            {option.recommended && (
                              <span className="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">
                                Recommended
                              </span>
                            )}
                          </button>
                        );
                      })}
                    </div>
                    {item.freeTextAllowed && (
                      <input
                        value={state.freeText}
                        onChange={(e) => handleBatchClarificationFreeText(item, e.target.value)}
                        className="mt-2 w-full border border-amber-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-amber-300"
                        placeholder="Or describe what this term should mean..."
                      />
                    )}
                  </div>
                );
              })}
            </div>
            <div className="mt-4 flex justify-end">
              <button
                onClick={handleBatchClarificationContinue}
                disabled={!allBatchItemsResolved()}
                className="px-4 py-2 rounded-lg bg-amber-700 text-white text-sm hover:bg-amber-800 disabled:opacity-50"
              >
                Continue
              </button>
            </div>
          </div>
        </div>
      )}

      {nlResult && !isLoading && shouldShowBlockingClarification(nlResult) && (!nlResult.clarificationItems || nlResult.clarificationItems.length === 0) && (
        <div className="mx-auto max-w-3xl p-4 xl:px-6">
          <div className={`border rounded-lg p-4 ${
            nlResult.needsExploratoryApproval
              ? 'border-sky-200 bg-sky-50'
              : 'border-amber-200 bg-amber-50'
          }`}>
            <div className={`text-sm font-semibold mb-1 ${
              nlResult.needsExploratoryApproval ? 'text-sky-900' : 'text-amber-900'
            }`}>
              {nlResult.needsExploratoryApproval ? 'One detail needed' : 'Clarification needed'}
            </div>
            <div className={`text-sm mb-3 ${
              nlResult.needsExploratoryApproval ? 'text-sky-900' : 'text-amber-900'
            }`}>
              {nlResult.question || 'Which option did you mean?'}
            </div>
            {nlResult.message && (
              <div className={`text-sm mb-3 ${
                nlResult.needsExploratoryApproval ? 'text-sky-900' : 'text-amber-900'
              }`}>
                {nlResult.message}
              </div>
            )}
            {formatResolverTrace(nlResult.resolverTrace).length > 0 && (
              <div className={`mb-3 rounded-md border bg-white px-3 py-2 ${
                nlResult.needsExploratoryApproval ? 'border-sky-200' : 'border-amber-200'
              }`}>
                <div className={`text-xs font-semibold uppercase tracking-wide ${
                  nlResult.needsExploratoryApproval ? 'text-sky-800' : 'text-amber-800'
                }`}>
                  Resolver checks
                </div>
                <ul className="mt-1 space-y-1 text-xs text-gray-700">
                  {formatResolverTrace(nlResult.resolverTrace).map((line) => (
                    <li key={line} className="flex gap-2">
                      <span className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${
                        nlResult.needsExploratoryApproval ? 'bg-sky-500' : 'bg-amber-500'
                      }`} />
                      <span>{line}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {nlResult.exploratoryPlan?.suggestions && nlResult.exploratoryPlan.suggestions.length > 0 && (
              <div className="mb-3 text-xs text-sky-800">
                {nlResult.exploratoryPlan.suggestions.join(' ')}
              </div>
            )}
            <div className="space-y-2">
              {(nlResult.options || []).map((option) => (
                <button
                  key={option.id}
                  onClick={() => handleClarificationChoice(option)}
                  className={`w-full flex items-center justify-between gap-3 text-left bg-white border px-3 py-2 rounded-lg text-sm text-gray-800 transition-colors ${
                    nlResult.needsExploratoryApproval
                      ? 'border-sky-200 hover:bg-sky-100'
                      : 'border-amber-200 hover:bg-amber-100'
                  }`}
                >
                  <span>{option.label}</span>
                  {option.recommended && (
                    <span className="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">
                      Recommended
                    </span>
                  )}
                </button>
              ))}
            </div>
            {nlResult.freeTextAllowed && (
              <div className="mt-3 flex gap-2">
                <input
                  value={clarificationFreeText}
                  onChange={(e) => setClarificationFreeText(e.target.value)}
                  className="flex-1 border border-amber-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-amber-300"
                  placeholder="Or describe what you meant..."
                />
                <button
                  onClick={() => handleClarificationChoice(null)}
                  disabled={!clarificationFreeText.trim()}
                  className="px-3 py-2 rounded-lg bg-amber-700 text-white text-sm hover:bg-amber-800 disabled:opacity-50"
                >
                  Continue
                </button>
              </div>
            )}
          </div>
        </div>
      )}
        </section>
        )}

        <button
          type="button"
          onPointerDown={handleWorkspaceResizeStart}
          className="group hidden w-3 shrink-0 cursor-col-resize items-center justify-center bg-gray-100 hover:bg-folio-50 lg:flex"
          aria-label="Resize Ask workspace columns"
          title="Drag to resize"
        >
          <span className="h-12 w-1 rounded-full bg-gray-300 group-hover:bg-folio-500" />
        </button>

        <section
          className="flex-1 bg-gray-50 lg:min-h-0 lg:basis-[var(--ask-right)] lg:h-full lg:overflow-y-auto"
          style={{ '--ask-right': `${100 - workspaceSplit}%` } as CSSProperties}
        >
          <div className="sticky top-0 z-10 hidden border-b border-gray-200 bg-gray-50/95 px-4 py-2 backdrop-blur lg:flex items-center justify-between">
            <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">Results workspace</div>
            <div className="flex items-center gap-1">
              <button onClick={() => setWorkspaceSplitPreset(45)} className="rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-100">Expand question</button>
              <button onClick={() => setWorkspaceSplitPreset(30)} className="rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-100">Expand results</button>
              <button onClick={() => setWorkspaceSplitPreset(ASK_WORKSPACE_DEFAULT_SPLIT)} className="rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-100">Reset</button>
            </div>
          </div>

          <div className="hidden border-b border-gray-200 bg-white p-4 xl:p-6">
            <div className="mx-auto max-w-5xl">
              <div className="flex items-center gap-2 mb-3">
                <Sparkles size={20} className="text-folio-600" />
                <h2 className="text-lg font-semibold">Ask AI</h2>
              </div>

              <div className="flex flex-wrap items-center gap-x-4 gap-y-2 mb-4">
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-500 font-medium flex-shrink-0">Scope to:</span>
                  <select
                    value={selectedCampus}
                    onChange={(e) => handleCampusChange(e.target.value)}
                    className="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:ring-2 focus:ring-folio-300 focus:border-folio-400 outline-none cursor-pointer"
                  >
                    {CAMPUS_OPTIONS.map((c) => (
                      <option key={c.code} value={c.name}>{c.name}</option>
                    ))}
                  </select>
                  {selectedCampus !== 'All Colleges' && (
                    <span className="text-xs text-folio-600">
                      Queries will be filtered to {selectedCampus}
                    </span>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-500 font-medium flex-shrink-0">Results:</span>
                  <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs">
                    <button
                      onClick={() => handleOutputPrefChange('preview')}
                      className={`px-3 py-1.5 transition-colors ${
                        outputPref === 'preview'
                          ? 'bg-folio-600 text-white'
                          : 'text-gray-600 hover:bg-gray-50'
                      }`}
                      title="Show up to 100 rows in the browser"
                    >
                      Preview (100 rows)
                    </button>
                    <button
                      onClick={() => handleOutputPrefChange('full')}
                      className={`px-3 py-1.5 border-l border-gray-200 transition-colors ${
                        outputPref === 'full'
                          ? 'bg-folio-600 text-white'
                          : 'text-gray-600 hover:bg-gray-50'
                      }`}
                      title="Export all rows as a downloadable CSV"
                    >
                      All results (CSV)
                    </button>
                  </div>
                </div>
              </div>

              <p className="text-sm text-gray-500 mb-4">
                Describe the report you need in plain English. The app checks known FOLIO terms first, then generates SQL when the request is clear.
              </p>

              {followUpContext && (
                <div className="flex items-center justify-between gap-3 mb-3 border border-folio-200 bg-folio-50 rounded-lg px-3 py-2 text-sm text-folio-800">
                  <span>
                    Asking a follow-up for {followUpContext.source === 'history' ? 'a history query' : 'the current result'}
                  </span>
                  <button
                    onClick={() => {
                      setFollowUpContext(null);
                      setFollowUpError(null);
                    }}
                    className="text-xs text-folio-700 hover:text-folio-900"
                  >
                    Cancel
                  </button>
                </div>
              )}

              <div className="flex gap-2">
                <textarea
                  value={prompt}
                  onChange={(e) => setPrompt(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                      e.preventDefault();
                      handleSubmit();
                    }
                  }}
                  placeholder="Describe the report you want..."
                  className="flex-1 border rounded-lg px-4 py-3 text-sm resize-none h-20 focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
                />
                <div className="flex flex-col gap-2 self-end">
                  <button
                    onClick={handleSubmit}
                    disabled={!prompt.trim() || askMut.isPending}
                    className="bg-folio-600 text-white px-4 py-3 rounded-lg hover:bg-folio-700 disabled:opacity-50 transition-colors"
                  >
                    {askMut.isPending ? (
                      <RotateCcw size={18} className="animate-spin" />
                    ) : (
                      <Send size={18} />
                    )}
                  </button>
                  {history.length > 0 && (
                    <div className="relative" ref={historyRef}>
                      <button
                        onClick={() => setHistoryOpen((o) => !o)}
                        className={`w-full flex items-center justify-center px-4 py-2 rounded-lg border transition-colors ${
                          historyOpen
                            ? 'bg-folio-50 text-folio-700 border-folio-300'
                            : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50 border-gray-200'
                        }`}
                        title="Recent questions"
                      >
                        <Clock size={16} />
                      </button>
                      {historyOpen && (
                        <div className="absolute right-0 top-full mt-1 w-96 max-h-80 overflow-y-auto bg-white border border-gray-200 rounded-xl shadow-lg z-40">
                          <div className="px-3 py-2 border-b text-xs font-semibold text-gray-500 uppercase tracking-wide">
                            Recent Questions
                          </div>
                          {history.map((h, i) => (
                            <button
                              key={i}
                              onClick={() => {
                                setPrompt(h.prompt);
                                setNlResult(h.result);
                                resetJob();
                                setActiveJobId(null);
                                setDetailTab('results');
                                setHistoryOpen(false);
                              }}
                              className="block w-full text-left text-sm text-gray-600 hover:text-folio-600 hover:bg-gray-50 px-3 py-2.5 border-b border-gray-50 last:border-0"
                            >
                              <span className="line-clamp-2">{h.prompt}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>

              <div className="flex flex-wrap gap-2 mt-3">
                {EXAMPLE_PROMPTS.map((ex, i) => (
                  <button
                    key={i}
                    onClick={() => {
                      setPrompt(ex);
                    }}
                    className="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1 rounded-full transition-colors"
                  >
                    {ex}
                  </button>
                ))}
              </div>
            </div>
          </div>

      {/* Results area */}
      <div className="flex-1">
        {/* Errors — always visible regardless of tab */}
        {showRightPaneAskErrors && askMut.isError && (
          <div className="max-w-4xl mx-auto m-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            {formatNlError(askMut.error)}
          </div>
        )}
        {showRightPaneAskErrors && followUpError && (
          <div className="max-w-4xl mx-auto m-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            {followUpError}
          </div>
        )}
        {nlResult && execMut.isError && (
          <div className="max-w-4xl mx-auto px-4 mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            {formatQuerySubmitError(execMut.error)}
          </div>
        )}
        {nlResult && jobError && (
          <div className="max-w-4xl mx-auto px-4 mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            {formatExecutionError(jobError)}
          </div>
        )}

        {/* Two-phase loading indicator */}
        {isExecuting && (
          <div className="flex items-center justify-center py-12">
            {isGenerating ? (
              <div className="w-full max-w-lg mx-4 rounded-lg border border-folio-200 bg-white p-4 shadow-sm">
                <div className="flex items-start gap-3">
                <Loader2 size={24} className="animate-spin text-folio-600" />
                  <div>
                    <div className="text-sm font-semibold text-gray-900">
                      {askProgressCopy.title}
                    </div>
                    <div className="mt-2 space-y-1.5">
                      {askProgressCopy.steps.map((step) => (
                        <div key={step} className="flex items-start gap-2 text-xs text-gray-600">
                          <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-folio-500" />
                          <span>{step}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </div>
            ) : (
              <div className="max-w-md w-full mx-4 bg-blue-50 border border-blue-200 rounded-xl p-5">
                <div className="flex items-start gap-3">
                  <Loader2 size={20} className="animate-spin text-blue-600 mt-0.5 flex-shrink-0" />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-semibold text-blue-800">
                      {job?.status === 'pending'
                        ? 'Queued — waiting for worker…'
                        : job?.status === 'pending_export'
                          ? 'Queued for CSV export…'
                          : 'Running query…'}
                    </div>
                    <div className="text-sm text-blue-600 mt-1">
                      Elapsed: <span className="font-mono font-medium">
                        {elapsedSeconds < 60 ? `${elapsedSeconds}s` : `${Math.floor(elapsedSeconds / 60)}m ${elapsedSeconds % 60}s`}
                      </span>
                      {elapsedSeconds >= 30 && (
                        <span className="ml-2 text-blue-500">— this is a large query, please wait…</span>
                      )}
                    </div>
                    <div className="mt-3 text-xs text-blue-500">
                      You can navigate away — the query will keep running.{' '}
                      <Link to="/history" className="underline font-medium hover:text-blue-700">Check History →</Link>
                    </div>
                  </div>
                  <button
                    onClick={cancelJobFn}
                    className="flex items-center gap-1 text-xs text-red-600 hover:text-red-800 border border-red-200 rounded px-2 py-1 flex-shrink-0"
                    title="Cancel query"
                  >
                    <Square size={11} /> Cancel
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Main content — only show when not in loading state */}
        {showRightPaneClarifications && nlResult && !isLoading && shouldShowBlockingClarification(nlResult) && nlResult.clarificationItems && nlResult.clarificationItems.length > 0 && (
          <div className="max-w-4xl mx-auto p-6">
            <div className="border border-amber-200 bg-amber-50 rounded-lg p-4">
              <div className="text-sm font-semibold mb-1 text-amber-900">
                Clarification needed
              </div>
              <div className="text-sm mb-3 text-amber-900">
                {nlResult.question || 'Confirm these local terms before I generate SQL.'}
              </div>
              {nlResult.message && (
                <div className="text-sm mb-3 text-amber-900">
                  {nlResult.message}
                </div>
              )}
              {formatResolverTrace(nlResult.resolverTrace).length > 0 && (
                <div className="mb-3 rounded-md border border-amber-200 bg-white px-3 py-2">
                  <div className="text-xs font-semibold uppercase tracking-wide text-amber-800">
                    Resolver checks
                  </div>
                  <ul className="mt-1 space-y-1 text-xs text-gray-700">
                    {formatResolverTrace(nlResult.resolverTrace).map((line) => (
                      <li key={line} className="flex gap-2">
                        <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                        <span>{line}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              <div className="space-y-3">
                {nlResult.clarificationItems.map((item) => {
                  const state = batchClarificationChoices[item.clarificationKey] || { option: null, freeText: '' };
                  return (
                    <div key={item.clarificationKey} className="bg-white border border-amber-200 rounded-lg p-3">
                      <div className="flex items-start justify-between gap-3 mb-2">
                        <div>
                          <div className="text-sm font-semibold text-gray-900">{item.term}</div>
                          <div className="text-sm text-gray-700">{item.question}</div>
                        </div>
                        {item.confidence && (
                          <span className="shrink-0 text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">
                            {item.confidence}
                          </span>
                        )}
                      </div>
                      <div className="space-y-2">
                        {(item.options || []).map((option) => {
                          const selected = state.option?.id === option.id;
                          return (
                            <button
                              key={option.id}
                              onClick={() => handleBatchClarificationOption(item, option)}
                              className={`w-full flex items-center justify-between gap-3 text-left border px-3 py-2 rounded-lg text-sm transition-colors ${
                                selected
                                  ? 'bg-amber-100 border-amber-400 text-amber-950'
                                  : 'bg-white border-amber-200 text-gray-800 hover:bg-amber-100'
                              }`}
                            >
                              <span>{option.label}</span>
                              {option.recommended && (
                                <span className="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">
                                  Recommended
                                </span>
                              )}
                            </button>
                          );
                        })}
                      </div>
                      {item.freeTextAllowed && (
                        <input
                          value={state.freeText}
                          onChange={(e) => handleBatchClarificationFreeText(item, e.target.value)}
                          className="mt-2 w-full border border-amber-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-amber-300"
                          placeholder="Or describe what this term should mean..."
                        />
                      )}
                    </div>
                  );
                })}
              </div>
              <div className="mt-4 flex justify-end">
                <button
                  onClick={handleBatchClarificationContinue}
                  disabled={!allBatchItemsResolved()}
                  className="px-4 py-2 rounded-lg bg-amber-700 text-white text-sm hover:bg-amber-800 disabled:opacity-50"
                >
                  Continue
                </button>
              </div>
            </div>
          </div>
        )}

        {showRightPaneClarifications && nlResult && !isLoading && shouldShowBlockingClarification(nlResult) && (!nlResult.clarificationItems || nlResult.clarificationItems.length === 0) && (
          <div className="max-w-4xl mx-auto p-6">
            <div className={`border rounded-lg p-4 ${
              nlResult.needsExploratoryApproval
                ? 'border-sky-200 bg-sky-50'
                : 'border-amber-200 bg-amber-50'
            }`}>
              <div className={`text-sm font-semibold mb-1 ${
                nlResult.needsExploratoryApproval ? 'text-sky-900' : 'text-amber-900'
              }`}>
                {nlResult.needsExploratoryApproval ? 'One detail needed' : 'Clarification needed'}
              </div>
              <div className={`text-sm mb-3 ${
                nlResult.needsExploratoryApproval ? 'text-sky-900' : 'text-amber-900'
              }`}>
                {nlResult.question || 'Which option did you mean?'}
              </div>
              {nlResult.message && (
                <div className={`text-sm mb-3 ${
                  nlResult.needsExploratoryApproval ? 'text-sky-900' : 'text-amber-900'
                }`}>
                  {nlResult.message}
                </div>
              )}
              {formatResolverTrace(nlResult.resolverTrace).length > 0 && (
                <div className={`mb-3 rounded-md border bg-white px-3 py-2 ${
                  nlResult.needsExploratoryApproval ? 'border-sky-200' : 'border-amber-200'
                }`}>
                  <div className={`text-xs font-semibold uppercase tracking-wide ${
                    nlResult.needsExploratoryApproval ? 'text-sky-800' : 'text-amber-800'
                  }`}>
                    Resolver checks
                  </div>
                  <ul className="mt-1 space-y-1 text-xs text-gray-700">
                    {formatResolverTrace(nlResult.resolverTrace).map((line) => (
                      <li key={line} className="flex gap-2">
                        <span className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${
                          nlResult.needsExploratoryApproval ? 'bg-sky-500' : 'bg-amber-500'
                        }`} />
                        <span>{line}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {nlResult.exploratoryPlan?.suggestions && nlResult.exploratoryPlan.suggestions.length > 0 && (
                <div className="mb-3 text-xs text-sky-800">
                  {nlResult.exploratoryPlan.suggestions.join(' ')}
                </div>
              )}
              <div className="space-y-2">
                {(nlResult.options || []).map((option) => (
                  <button
                    key={option.id}
                    onClick={() => handleClarificationChoice(option)}
                    className={`w-full flex items-center justify-between gap-3 text-left bg-white border px-3 py-2 rounded-lg text-sm text-gray-800 transition-colors ${
                      nlResult.needsExploratoryApproval
                        ? 'border-sky-200 hover:bg-sky-100'
                        : 'border-amber-200 hover:bg-amber-100'
                    }`}
                  >
                    <span>{option.label}</span>
                    {option.recommended && (
                      <span className="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">
                        Recommended
                      </span>
                    )}
                  </button>
                ))}
              </div>
              {nlResult.freeTextAllowed && (
                <div className="mt-3 flex gap-2">
                  <input
                    value={clarificationFreeText}
                    onChange={(e) => setClarificationFreeText(e.target.value)}
                    className="flex-1 border border-amber-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-amber-300"
                    placeholder="Or describe what you meant..."
                  />
                  <button
                    onClick={() => handleClarificationChoice(null)}
                    disabled={!clarificationFreeText.trim()}
                    className="px-3 py-2 rounded-lg bg-amber-700 text-white text-sm hover:bg-amber-800 disabled:opacity-50"
                  >
                    Continue
                  </button>
                </div>
              )}
            </div>
          </div>
        )}

        {nlResult && !isLoading && !shouldShowBlockingClarification(nlResult) && isExploratoryValidationHardStop(nlResult.validationSummary) && (
          <div className="mx-auto w-full max-w-4xl p-3">
            <ExploratoryRecoveryPanel
              response={nlResult}
              onRetry={handleRetryExploratory}
              onRefine={handleRefineExploratory}
            />
          </div>
        )}

        {nlResult && !isLoading && !shouldShowBlockingClarification(nlResult) && !isExploratoryValidationHardStop(nlResult.validationSummary) && (
          <div className="mx-auto w-full max-w-6xl p-3 space-y-3">
            <ExploratoryNoticePanel result={nlResult} />
            {nlResult.mode === 'exploratory' && nlResult.assumptions && nlResult.assumptions.length > 0 && (
              <ExploratoryAssumptionsPanel
                assumptions={nlResult.assumptions}
                repairCount={nlResult.repairAttempts ?? nlResult.validationSummary?.repairAttempts ?? 0}
                onCorrect={handleCorrectAssumption}
              />
            )}

            {/* Tab toggle bar */}
            <div className="flex items-center gap-1 border-b pb-0">
              {ASK_RESULT_TABS.map((tab) => (
                <button
                  key={tab.id}
                  onClick={() => setDetailTab(tab.id)}
                  className={`flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium border-b-2 transition-colors -mb-px ${
                    detailTab === tab.id
                      ? 'border-folio-600 text-folio-700'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                  }`}
                >
                  {tab.id === 'results' ? <Table2 size={14} /> : tab.id === 'sql' ? <Code2 size={14} /> : <Sparkles size={14} />}
                  {tab.label}
                  {tab.id === 'results' && results && (
                    <span className="ml-1 px-1.5 py-0.5 bg-gray-100 text-gray-600 text-xs rounded-full">
                      {results.rowCount.toLocaleString()}
                    </span>
                  )}
                  {tab.id === 'followups' && effectiveSuggestions.length > 0 && (
                    <span className="ml-1 px-1.5 py-0.5 bg-gray-100 text-gray-600 text-xs rounded-full">
                      {effectiveSuggestions.length}
                    </span>
                  )}
                </button>
              ))}
            </div>

            {/* ===== Results tab ===== */}
            {detailTab === 'results' && (
              <>
                {results ? (
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <div className="text-xs text-gray-500">
                        {results.rowCount.toLocaleString()} row{results.rowCount !== 1 ? 's' : ''}
                        {results.executionTimeMs != null && (
                          <> &middot; {(results.executionTimeMs / 1000).toFixed(2)}s</>
                        )}
                        {nlResult.mode === 'exploratory' && (
                          <> &middot; exploratory SQL</>
                        )}
                      </div>
                      <div className="flex items-center gap-3">
                        {nlResult.sql && (
                          <button
                            onClick={handleStartCurrentFollowUp}
                            className="flex items-center gap-1 text-xs text-folio-600 hover:text-folio-800"
                          >
                            <Sparkles size={12} /> Ask follow-up
                          </button>
                        )}
                        <button
                          onClick={() => setDetailTab('sql')}
                          className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                        >
                          <Code2 size={12} /> View SQL
                        </button>
                        {results.outputMode !== 'file' && (
                          <button
                            onClick={() => setModalOpen(true)}
                            className="flex items-center gap-1 text-xs text-folio-600 hover:text-folio-800"
                          >
                            <Maximize2 size={12} /> Expand
                          </button>
                        )}
                      </div>
                    </div>
                    <div className="mb-2 border border-gray-200 bg-gray-50 rounded-lg p-2">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-xs font-medium text-gray-600">Were these results accurate?</span>
                        <button
                          onClick={() => feedbackMut.mutate('accurate')}
                          disabled={feedbackMut.isPending}
                          className="px-2.5 py-1 rounded border border-green-200 bg-white text-xs text-green-700 hover:bg-green-50 disabled:opacity-50"
                        >
                          Yes
                        </button>
                        <button
                          onClick={() => feedbackMut.mutate('inaccurate')}
                          disabled={feedbackMut.isPending}
                          className="px-2.5 py-1 rounded border border-red-200 bg-white text-xs text-red-700 hover:bg-red-50 disabled:opacity-50"
                        >
                          No
                        </button>
                        <button
                          onClick={() => feedbackMut.mutate('unsure')}
                          disabled={feedbackMut.isPending}
                          className="px-2.5 py-1 rounded border border-gray-200 bg-white text-xs text-gray-600 hover:bg-gray-100 disabled:opacity-50"
                        >
                          Unsure
                        </button>
                        <input
                          value={feedbackNote}
                          onChange={(e) => setFeedbackNote(e.target.value)}
                          className="min-w-[220px] flex-1 border border-gray-200 rounded px-2 py-1 text-xs outline-none focus:ring-2 focus:ring-folio-200"
                          placeholder="Optional note"
                        />
                        {feedbackMessage && (
                          <span className="text-xs text-green-700">{feedbackMessage}</span>
                        )}
                      </div>
                    </div>
                    {results.outputMode === 'file' ? (
                      <div className="space-y-3">
                        <div className="border border-blue-200 bg-blue-50 rounded-lg p-4 text-sm text-blue-800">
                          <div className="font-medium mb-1">Export complete</div>
                          <div className="mb-3">
                            {hasFilePreview
                              ? 'Preview shown below. Download CSV for the full result set.'
                              : 'This query was exported as CSV in the background. Download the file to view all data.'}
                          </div>
                          {results.downloadUrl && activeJobId && (
                            <button
                              onClick={() => downloadExportCsv(activeJobId)}
                              className="inline-flex items-center gap-1 px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700 text-xs"
                            >
                              Download full CSV
                            </button>
                          )}
                        </div>
                        {hasFilePreview && <ResultsTable data={results} />}
                      </div>
                    ) : (
                      <ResultsTable data={results} />
                    )}
                  </div>
                ) : (
                  <div className="flex items-center justify-center h-32 text-gray-400 text-sm">
                    No results yet — query may still be running.
                  </div>
                )}
              </>
            )}

            {/* ===== Related follow-ups tab ===== */}
            {detailTab === 'followups' && (
              <div className="space-y-3">
                {effectiveSuggestions.length > 0 ? (
                  <>
                    <div className="flex items-center justify-between gap-2">
                      <div className="text-sm font-semibold text-folio-800">Suggested follow-up queries</div>
                      {usingFallbackSuggestions && (
                        <div className="text-xs text-folio-700/80">Fallback suggestions shown</div>
                      )}
                    </div>
                    <div className="space-y-2">
                      {effectiveSuggestions.map((suggestion, i) => (
                        <div key={`${suggestion}-${i}`} className="flex items-center gap-2">
                          <button
                            onClick={() => handleUseSuggestion(suggestion)}
                            className="flex-1 text-left text-xs bg-white border border-folio-200 text-folio-700 hover:bg-folio-50 px-3 py-2 rounded-lg transition-colors"
                            title="Load this suggestion into Ask AI"
                          >
                            {suggestion}
                          </button>
                          <button
                            onClick={() => handleRunSuggestion(suggestion)}
                            disabled={askMut.isPending || execMut.isPending || isRunning}
                            className="inline-flex items-center gap-1 text-xs px-2.5 py-2 rounded-lg bg-folio-600 text-white hover:bg-folio-700 disabled:opacity-50"
                            title="Run this suggestion now"
                          >
                            <Play size={12} /> Run
                          </button>
                        </div>
                      ))}
                    </div>
                  </>
                ) : (
                  <div className="flex items-center justify-center h-32 text-gray-400 text-sm">
                    No related follow-ups are available for this result.
                  </div>
                )}
              </div>
            )}

            {/* ===== Output SQL tab ===== */}
            {detailTab === 'sql' && (
              <div className="space-y-4">
                {/* Explanation */}
                {nlResult.explanation && (
                  <div className="bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-800">
                    <strong>AI Explanation:</strong> {nlResult.explanation}
                  </div>
                )}

                {nlResult.mode === 'exploratory' && (
                  <div className="bg-sky-50 border border-sky-100 rounded-lg p-4 text-sm text-sky-800">
                    <strong>Exploratory SQL:</strong> {nlResult.repeatabilityWarning || 'This AI-assisted query may vary between runs until this request type is reviewed and promoted to a verified report pattern.'}
                  </div>
                )}

                {/* Warnings */}
                {nlResult.warnings && nlResult.warnings.length > 0 && (
                  <div className="bg-yellow-50 border border-yellow-100 rounded-lg p-3 text-sm text-yellow-700">
                    {nlResult.warnings.map((w, i) => (
                      <div key={i}>⚠ {w}</div>
                    ))}
                  </div>
                )}

                {/* SQL + action buttons */}
                {nlResult.sql && (
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <h3 className="text-sm font-semibold">Generated SQL</h3>
                      <div className="flex gap-2">
                        <button
                          onClick={handleStartCurrentFollowUp}
                          className="flex items-center gap-1 text-xs text-folio-600 hover:text-folio-800"
                        >
                          <Sparkles size={12} /> Ask follow-up
                        </button>
                        <button
                          onClick={handleCopy}
                          className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                        >
                          <Copy size={12} /> Copy
                        </button>
                        <button
                          onClick={() => setSaveOpen(true)}
                          className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                        >
                          <Save size={12} /> Save
                        </button>
                        {lastSavedId && (
                          <button
                            onClick={() => promoteMut.mutate(lastSavedId)}
                            disabled={promoteMut.isPending}
                            className="flex items-center gap-1 text-xs text-purple-600 hover:text-purple-800"
                          >
                            <FileBarChart size={12} />
                            {promoteMut.isPending ? 'Promoting…' : 'Save as Report'}
                          </button>
                        )}
                        {!isRunning ? (
                          <button
                            onClick={() => nlResult.sql && execMut.mutate({ sql: nlResult.sql, dataSource: nlResult.dataSource || 'folio' })}
                            disabled={execMut.isPending || !nlResult.sql}
                            className="flex items-center gap-1 bg-green-600 text-white text-xs px-3 py-1 rounded hover:bg-green-700 disabled:opacity-50"
                          >
                            <Play size={12} />
                            {execMut.isPending ? 'Submitting…' : 'Re-run Query'}
                          </button>
                        ) : (
                          <button
                            onClick={cancelJobFn}
                            className="flex items-center gap-1 bg-red-600 text-white text-xs px-3 py-1 rounded hover:bg-red-700"
                          >
                            <Square size={12} /> Cancel
                          </button>
                        )}
                        <button
                          onClick={() => {
                            setCorrecting(true);
                            setCorrectedSql(nlResult.sql || '');
                          }}
                          className="flex items-center gap-1 text-xs text-amber-600 hover:text-amber-800"
                          title="Correct this SQL — your fix teaches the AI"
                        >
                          <ThumbsDown size={12} /> Correct
                        </button>
                      </div>
                    </div>
                    <SqlPreview sql={nlResult.sql} height="180px" />

                    {/* Correction panel */}
                    {correcting && (
                      <div className="mt-3 border border-amber-200 bg-amber-50 rounded-lg p-4">
                        <div className="flex items-center justify-between mb-3">
                          <div className="flex items-center gap-2">
                            <Pencil size={14} className="text-amber-600" />
                            <h4 className="text-sm font-semibold text-amber-800">Correct this query</h4>
                          </div>
                          <button onClick={() => setCorrecting(false)} className="text-gray-400 hover:text-gray-600">
                            <X size={14} />
                          </button>
                        </div>
                        <p className="text-xs text-amber-700 mb-3">
                          Edit the SQL below to fix the issue. Your correction will be saved as a training example
                          so the AI learns from it for future queries.
                        </p>
                        <textarea
                          value={correctedSql}
                          onChange={(e) => setCorrectedSql(e.target.value)}
                          className="w-full border border-amber-300 rounded px-3 py-2 text-xs font-mono h-40 resize-none focus:ring-2 focus:ring-amber-300 outline-none bg-white"
                        />
                        <input
                          value={correctionNotes}
                          onChange={(e) => setCorrectionNotes(e.target.value)}
                          placeholder="What was wrong? (optional note)"
                          className="w-full border border-amber-300 rounded px-3 py-2 text-sm mt-2 focus:ring-2 focus:ring-amber-300 outline-none bg-white"
                        />
                        <div className="flex justify-end gap-2 mt-3">
                          <button
                            onClick={() => setCorrecting(false)}
                            className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50"
                          >
                            Cancel
                          </button>
                          <button
                            onClick={() => correctionMut.mutate()}
                            disabled={correctionMut.isPending || !correctedSql.trim()}
                            className="flex items-center gap-1 px-3 py-1.5 text-sm bg-amber-600 text-white rounded hover:bg-amber-700 disabled:opacity-50"
                          >
                            <Save size={12} />
                            {correctionMut.isPending ? 'Saving…' : 'Save Correction'}
                          </button>
                        </div>
                        {correctionMut.isError && (
                          <div className="mt-2 text-xs text-red-600">
                            Error: {String(correctionMut.error)}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* Empty state — only when nothing happening */}
        {!nlResult && !isLoading && (
          <div className="flex items-center justify-center h-64 text-gray-400 text-sm">
            Ask a question above and the AI will generate a report for you.
          </div>
        )}
      </div>
        </section>
      </div>

      {/* Results modal (expanded view) */}
      {modalOpen && results && (
        <ResultsModal
          data={results}
          onClose={() => setModalOpen(false)}
          title={history[0]?.prompt}
        />
      )}

      {/* Save dialog */}
      {saveOpen && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-xl p-6 w-96">
            <h3 className="font-semibold mb-4">Save AI Query</h3>
            <input
              placeholder="Query name"
              value={saveName}
              onChange={(e) => setSaveName(e.target.value)}
              className="border rounded w-full px-3 py-2 mb-3 text-sm"
              autoFocus
            />
            <textarea
              placeholder="Description (optional)"
              value={saveDesc}
              onChange={(e) => setSaveDesc(e.target.value)}
              className="border rounded w-full px-3 py-2 mb-3 text-sm h-20 resize-none"
            />
            <div className="bg-gray-50 rounded p-2 mb-4 text-xs text-gray-500">
              <strong>Question:</strong> {history[0]?.prompt || prompt}
            </div>
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setSaveOpen(false)}
                className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={() => savedMut.mutate()}
                disabled={!saveName || savedMut.isPending}
                className="px-3 py-1.5 text-sm bg-folio-600 text-white rounded hover:bg-folio-700 disabled:opacity-50"
              >
                {savedMut.isPending ? 'Saving…' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
