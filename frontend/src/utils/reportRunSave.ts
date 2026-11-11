import type { ReportRunMetadata, ReportRunReason } from '../types';

const reasonText: Record<ReportRunReason, string> = {
  older_run: "Run this report again to save it - older runs didn't keep their settings.",
  invalid_snapshot: "This run's saved settings can't be read - run the report again to save it.",
  report_retired: 'This report has been retired.',
  report_deleted: 'This report no longer exists.',
};

const invalidSnapshot = reasonText.invalid_snapshot;

export function reportRunSaveState(item: { source: string; reportRun?: unknown }): {
  canSave: boolean;
  reason: string | null;
} {
  if (item.source !== 'report') return { canSave: true, reason: null };
  if (!item.reportRun || typeof item.reportRun !== 'object' || Array.isArray(item.reportRun)) {
    return { canSave: false, reason: invalidSnapshot };
  }

  const reportRun = item.reportRun as ReportRunMetadata;
  if (
    reportRun.canSave === true
    && typeof reportRun.reportTemplateId === 'number'
    && Number.isInteger(reportRun.reportTemplateId)
    && reportRun.reportTemplateId > 0
    && reportRun.reason === null
  ) return { canSave: true, reason: null };

  if (
    reportRun.canSave === false
    && reportRun.reason
    && Object.prototype.hasOwnProperty.call(reasonText, reportRun.reason)
  ) return { canSave: false, reason: reasonText[reportRun.reason] };
  return { canSave: false, reason: invalidSnapshot };
}
