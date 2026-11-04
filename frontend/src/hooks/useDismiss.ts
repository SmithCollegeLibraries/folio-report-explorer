import { useEffect, useRef, type RefObject } from 'react';

type DismissRef = RefObject<HTMLElement | null>;

export function useDismiss(
  refs: readonly DismissRef[],
  open: boolean,
  onClose: () => void,
): void {
  const refsRef = useRef(refs);
  const onCloseRef = useRef(onClose);
  refsRef.current = refs;
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!open) return undefined;

    const handlePointerDown = (event: PointerEvent) => {
      const target = event.target;
      if (!(target instanceof Node)) return;
      const isInside = refsRef.current.some((ref) => ref.current?.contains(target));
      if (!isInside) onCloseRef.current();
    };

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      refsRef.current[0]?.current?.focus();
      onCloseRef.current();
    };

    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open]);
}
