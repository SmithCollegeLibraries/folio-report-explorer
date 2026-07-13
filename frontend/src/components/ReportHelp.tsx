import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Info, X } from 'lucide-react';

interface ReportHelpProps {
  reportName: string;
  helpText: string;
}

const focusableSelector = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

export default function ReportHelp({ reportName, helpText }: ReportHelpProps) {
  const [open, setOpen] = useState(false);
  const titleId = useId();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const dialogRef = useRef<HTMLElement>(null);
  const wasOpenRef = useRef(false);

  const close = useCallback(() => setOpen(false), []);

  useEffect(() => {
    if (!open) {
      return;
    }

    const appRoot = document.getElementById('root');
    const hadInert = appRoot?.hasAttribute('inert') ?? false;
    const previousInert = appRoot?.getAttribute('inert');
    const hadAriaHidden = appRoot?.hasAttribute('aria-hidden') ?? false;
    const previousAriaHidden = appRoot?.getAttribute('aria-hidden');

    appRoot?.setAttribute('inert', '');
    appRoot?.setAttribute('aria-hidden', 'true');

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        close();
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const dialog = dialogRef.current;
      if (!dialog) {
        return;
      }

      const focusableElements = Array.from(
        dialog.querySelectorAll<HTMLElement>(focusableSelector),
      );
      const firstFocusable = focusableElements[0];
      const lastFocusable = focusableElements[focusableElements.length - 1];

      if (!firstFocusable || !lastFocusable) {
        event.preventDefault();
        return;
      }

      if (event.shiftKey) {
        if (
          document.activeElement === firstFocusable ||
          !dialog.contains(document.activeElement)
        ) {
          event.preventDefault();
          lastFocusable.focus();
        }
        return;
      }

      if (
        document.activeElement === lastFocusable ||
        !dialog.contains(document.activeElement)
      ) {
        event.preventDefault();
        firstFocusable.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);

      if (!appRoot) {
        return;
      }

      if (hadInert) {
        appRoot.setAttribute('inert', previousInert ?? '');
      } else {
        appRoot.removeAttribute('inert');
      }

      if (hadAriaHidden) {
        appRoot.setAttribute('aria-hidden', previousAriaHidden ?? '');
      } else {
        appRoot.removeAttribute('aria-hidden');
      }
    };
  }, [close, open]);

  useEffect(() => {
    if (open) {
      wasOpenRef.current = true;
      closeRef.current?.focus();
      return;
    }

    if (wasOpenRef.current) {
      wasOpenRef.current = false;
      triggerRef.current?.focus();
    }
  }, [open]);

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen(true)}
        className="inline-flex items-center gap-1.5 rounded-xl border border-stone-200 px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-stone-50"
      >
        <Info size={16} /> How to read this report
      </button>

      {open &&
        createPortal(
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            onMouseDown={(event) => {
              if (event.target === event.currentTarget) {
                close();
              }
            }}
          >
            <section
              ref={dialogRef}
              role="dialog"
              aria-modal="true"
              aria-labelledby={titleId}
              className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"
            >
              <header className="flex items-center justify-between border-b border-stone-200 px-6 py-4">
                <h2 id={titleId} className="text-lg font-semibold text-gray-900">
                  {reportName} help
                </h2>
                <button
                  ref={closeRef}
                  type="button"
                  onClick={close}
                  aria-label="Close report help"
                  className="rounded-lg p-2 text-stone-500 transition-colors hover:bg-stone-100 hover:text-stone-800"
                >
                  <X size={18} />
                </button>
              </header>
              <div className="whitespace-pre-line px-6 py-5 text-sm leading-6 text-gray-700">
                {helpText}
              </div>
              <footer className="flex justify-end border-t border-stone-200 px-6 py-4">
                <button
                  type="button"
                  onClick={close}
                  className="rounded-xl bg-folio-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-folio-700"
                >
                  Close
                </button>
              </footer>
            </section>
          </div>,
          document.body,
        )}
    </>
  );
}
