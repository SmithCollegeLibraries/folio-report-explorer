import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useRef } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useDismiss } from './useDismiss';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

function DismissHarness({ open, onClose }: { open: boolean; onClose: () => void }) {
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  useDismiss([triggerRef, panelRef], open, onClose);

  return (
    <>
      <button ref={triggerRef} type="button">Trigger</button>
      <div ref={panelRef}>Inside panel</div>
      <button type="button">Outside</button>
    </>
  );
}

describe('useDismiss', () => {
  it('does not close when pointerdown occurs inside a referenced element', () => {
    const onClose = vi.fn();
    render(<DismissHarness open onClose={onClose} />);

    fireEvent.pointerDown(screen.getByText('Inside panel'));

    expect(onClose).not.toHaveBeenCalled();
  });

  it('closes when pointerdown occurs outside the referenced elements', () => {
    const onClose = vi.fn();
    render(<DismissHarness open onClose={onClose} />);

    fireEvent.pointerDown(screen.getByRole('button', { name: 'Outside' }));

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('is inert while closed', () => {
    const onClose = vi.fn();
    render(<DismissHarness open={false} onClose={onClose} />);

    fireEvent.pointerDown(screen.getByRole('button', { name: 'Outside' }));
    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).not.toHaveBeenCalled();
  });

  it('closes on Escape', () => {
    const onClose = vi.fn();
    render(<DismissHarness open onClose={onClose} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('removes its document listeners on unmount', () => {
    const addListener = vi.spyOn(document, 'addEventListener');
    const removeListener = vi.spyOn(document, 'removeEventListener');
    const { unmount } = render(<DismissHarness open onClose={vi.fn()} />);
    const pointerListener = addListener.mock.calls.find(([type]) => type === 'pointerdown')?.[1];
    const keyListener = addListener.mock.calls.find(([type]) => type === 'keydown')?.[1];

    unmount();

    expect(pointerListener).toEqual(expect.any(Function));
    expect(keyListener).toEqual(expect.any(Function));
    expect(removeListener.mock.calls).toContainEqual(
      expect.arrayContaining(['pointerdown', pointerListener]),
    );
    expect(removeListener.mock.calls).toContainEqual(
      expect.arrayContaining(['keydown', keyListener]),
    );
  });
});
