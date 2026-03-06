import { Clock, Loader2, CheckCircle2, XCircle, StopCircle } from 'lucide-react';
import type { JobStatus } from '../types';

export const STATUS_CONFIG: Record<JobStatus, { label: string; cls: string; icon: React.ElementType }> = {
  pending:   { label: 'Queued',    cls: 'bg-amber-100 text-amber-700',  icon: Clock },
  running:   { label: 'Running',   cls: 'bg-blue-100 text-blue-700',    icon: Loader2 },
  completed: { label: 'Completed', cls: 'bg-green-100 text-green-700',  icon: CheckCircle2 },
  failed:    { label: 'Failed',    cls: 'bg-red-100 text-red-700',      icon: XCircle },
  cancelled: { label: 'Cancelled', cls: 'bg-gray-100 text-gray-500',    icon: StopCircle },
};

interface Props {
  status: JobStatus;
}

export default function StatusBadge({ status }: Props) {
  const cfg = STATUS_CONFIG[status] ?? STATUS_CONFIG.cancelled;
  const Icon = cfg.icon;
  return (
    <span className={`inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded ${cfg.cls}`}>
      <Icon size={11} className={status === 'running' ? 'animate-spin' : ''} />
      {cfg.label}
    </span>
  );
}
