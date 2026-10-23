import { Bookmark } from 'lucide-react';

interface ReportPinButtonProps {
  reportName: string;
  isPinned: boolean;
  onToggle: () => void;
}

export default function ReportPinButton({ reportName, isPinned, onToggle }: ReportPinButtonProps) {
  const label = `${isPinned ? 'Unpin' : 'Pin'} ${reportName}`;

  return (
    <button
      type="button"
      onClick={onToggle}
      aria-label={label}
      aria-pressed={isPinned}
      title={label}
      className={`rounded-lg border p-2 transition-colors ${isPinned
        ? 'border-folio-200 bg-folio-50 text-folio-700 hover:bg-folio-100'
        : 'border-stone-200 text-stone-500 hover:bg-stone-100 hover:text-stone-800'}`}
    >
      <Bookmark size={16} fill={isPinned ? 'currentColor' : 'none'} />
    </button>
  );
}
