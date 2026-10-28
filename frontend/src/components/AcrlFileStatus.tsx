interface AcrlFileStatusProps {
  message: string | null;
  busy?: boolean;
  className?: string;
}

export default function AcrlFileStatus({ message, busy = false, className = '' }: AcrlFileStatusProps) {
  if (!message) return null;

  return <p role="status" aria-live="polite" aria-busy={busy} className={`flex items-center gap-2 text-sm text-folio-700 ${className}`}>
    {busy && <span aria-hidden="true" className="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-current border-r-transparent" />}
    {message}
  </p>;
}
