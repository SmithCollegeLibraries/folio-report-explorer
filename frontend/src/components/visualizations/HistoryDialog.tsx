import { useEffect, useId, useRef, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { HistoryPanel } from './HistoryPanel';

const focusableSelector = [
  'a[href]', 'button:not([disabled])', 'input:not([disabled])', 'select:not([disabled])',
  'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
].join(',');

interface HistoryDialogProps {
  unitCode: string;
  currentYear: number | null;
  triggerRef: RefObject<HTMLButtonElement>;
  onClose: () => void;
}

export function HistoryDialog({ unitCode, currentYear, triggerRef, onClose }: HistoryDialogProps) {
  const titleId = useId();
  const dialogRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const appRoot = document.getElementById('root');
    const previousInert = appRoot?.getAttribute('inert') ?? null;
    const previousAriaHidden = appRoot?.getAttribute('aria-hidden') ?? null;
    const previousOverflow = document.body.style.overflow;

    appRoot?.setAttribute('inert', '');
    appRoot?.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = 'hidden';
    closeRef.current?.focus();

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
        return;
      }
      if (event.key !== 'Tab') return;

      const dialog = dialogRef.current;
      if (!dialog) return;
      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(focusableSelector));
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!first || !last) {
        event.preventDefault();
        dialog.focus();
      } else if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
      if (appRoot) {
        if (previousInert === null) appRoot.removeAttribute('inert');
        else appRoot.setAttribute('inert', previousInert);
        if (previousAriaHidden === null) appRoot.removeAttribute('aria-hidden');
        else appRoot.setAttribute('aria-hidden', previousAriaHidden);
      }
      triggerRef.current?.focus();
    };
  }, [onClose, triggerRef]);

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/45 p-3 sm:p-6" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}>
      <section ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}
        className="flex max-h-[min(88vh,52rem)] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-gray-300 bg-white shadow-2xl">
        <header className="flex shrink-0 items-center justify-between gap-4 border-b border-gray-200 bg-gray-50 px-5 py-4 sm:px-6">
          <h2 id={titleId} className="text-xl font-semibold text-gray-950">Historical figures</h2>
          <button ref={closeRef} type="button" onClick={onClose}
            className="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
            Close
          </button>
        </header>
        <div className="min-h-0 flex-1 overflow-y-auto">
          <HistoryPanel key={unitCode} unitCode={unitCode} currentYear={currentYear} />
        </div>
      </section>
    </div>,
    document.body,
  );
}
