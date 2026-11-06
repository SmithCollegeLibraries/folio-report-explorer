import { useEffect, useId, useRef, type ReactNode, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { ChartLabelSizeContext } from './ChartLabelSize';

export const chartEnlargementOpenEvent = 'folio-chart-enlargement-open';

const focusableSelector = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

interface ChartEnlargementDialogProps {
  title: string;
  explanation: string;
  children: ReactNode;
  controls?: ReactNode;
  legend?: ReactNode;
  triggerRef: RefObject<HTMLButtonElement>;
  onClose: () => void;
}

export function ChartEnlargementDialog({
  title, explanation, children, controls, legend, triggerRef, onClose,
}: ChartEnlargementDialogProps) {
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const fullscreenAvailable = typeof document !== 'undefined'
    && document.fullscreenEnabled === true
    && typeof HTMLElement !== 'undefined'
    && typeof HTMLElement.prototype.requestFullscreen === 'function';

  useEffect(() => {
    const appRoot = document.getElementById('root');
    const hadInert = appRoot?.hasAttribute('inert') ?? false;
    const previousInert = appRoot?.getAttribute('inert');
    const hadAriaHidden = appRoot?.hasAttribute('aria-hidden') ?? false;
    const previousAriaHidden = appRoot?.getAttribute('aria-hidden');

    appRoot?.setAttribute('inert', '');
    appRoot?.setAttribute('aria-hidden', 'true');
    closeRef.current?.focus();
    const closeForAnotherChart = () => onClose();

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
        return;
      }
      if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    window.addEventListener(chartEnlargementOpenEvent, closeForAnotherChart);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener(chartEnlargementOpenEvent, closeForAnotherChart);

      if (appRoot) {
        if (hadInert) appRoot.setAttribute('inert', previousInert ?? '');
        else appRoot.removeAttribute('inert');
        if (hadAriaHidden) appRoot.setAttribute('aria-hidden', previousAriaHidden ?? '');
        else appRoot.removeAttribute('aria-hidden');
      }
      triggerRef.current?.focus();
    };
  }, [onClose, triggerRef]);

  const requestFullscreen = () => {
    const dialog = dialogRef.current;
    if (!dialog?.requestFullscreen) return;
    void dialog.requestFullscreen().catch(() => undefined);
  };

  return createPortal(
    <ChartLabelSizeContext.Provider value={18}>
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        onClick={(event) => {
          if (event.target === event.currentTarget) onClose();
        }}
        className="fixed inset-0 z-50 flex items-stretch justify-stretch bg-black/50 p-2 sm:p-4"
      >
        <section className="flex h-full w-full min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl">
          <header className="flex shrink-0 items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 sm:px-6">
            <h2 id={titleId} className="text-lg font-semibold text-gray-900">{title}</h2>
            <div className="flex shrink-0 items-center gap-2">
              {fullscreenAvailable && <button type="button" onClick={requestFullscreen}
                className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Full screen
              </button>}
              <button ref={closeRef} type="button" onClick={onClose} aria-label="Close"
                className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Close
              </button>
            </div>
          </header>
          <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
            <p className="mb-4 text-sm text-gray-600">{explanation}</p>
            {controls}
            {legend}
            <div className="min-w-0 [&_.h-72]:!h-[min(70vh,760px)] [&_.h-80]:!h-[min(70vh,760px)]">
              {children}
            </div>
          </div>
        </section>
      </div>
    </ChartLabelSizeContext.Provider>,
    document.body,
  );
}
