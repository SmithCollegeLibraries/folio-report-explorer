import { useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';

const TABS = ['acquisitions', 'gate-counts'] as const;
type VisualizationTab = typeof TABS[number];

export function VisualizationTabs({ acquisitions, gateCounts }: {
  acquisitions: ReactNode; gateCounts: ReactNode;
}) {
  const [searchParams, setSearchParams] = useSearchParams();
  const selected = searchParams.get('tab') === 'gate-counts' ? 'gate-counts' : 'acquisitions';
  const [visited, setVisited] = useState(() => new Set<VisualizationTab>([selected]));
  const tablistId = useId();
  const tabRefs = useRef<Partial<Record<VisualizationTab, HTMLButtonElement>>>({});

  const selectTab = (tab: VisualizationTab) => {
    setVisited((current) => new Set(current).add(tab));
    setSearchParams((current) => {
      const next = new URLSearchParams(current);
      next.set('tab', tab);
      return next;
    }, { replace: true });
  };

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, tab: VisualizationTab) => {
    const index = TABS.indexOf(tab);
    const nextIndex = event.key === 'ArrowRight' ? (index + 1) % TABS.length
      : event.key === 'ArrowLeft' ? (index + TABS.length - 1) % TABS.length
        : event.key === 'Home' ? 0 : event.key === 'End' ? TABS.length - 1 : -1;
    if (nextIndex < 0) return;
    event.preventDefault();
    const next = TABS[nextIndex];
    selectTab(next);
    tabRefs.current[next]?.focus();
  };

  const groups: Record<VisualizationTab, { label: string; content: ReactNode }> = {
    acquisitions: { label: 'Acquisitions', content: acquisitions },
    'gate-counts': { label: 'Gate counts', content: gateCounts },
  };

  return <>
    <div className="w-full max-w-full overflow-x-auto overscroll-x-contain">
      <div id={tablistId} role="tablist" aria-label="Visualization groups" className="flex min-w-max gap-2 border-b border-gray-200">
        {TABS.map((tab) => {
          const active = tab === selected;
          const id = tablistId + '-' + tab;
          return <button key={tab} ref={(element) => { tabRefs.current[tab] = element ?? undefined; }}
            id={id} type="button" role="tab" aria-selected={active} aria-controls={id + '-panel'}
            tabIndex={active ? 0 : -1} onClick={() => selectTab(tab)} onKeyDown={(event) => onKeyDown(event, tab)}
            className={'shrink-0 border-b-2 px-4 py-3 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-folio-700 '
              + (active ? 'border-folio-700 text-folio-800' : 'border-transparent text-gray-600 hover:text-gray-900')}>
            {groups[tab].label}
          </button>;
        })}
      </div>
    </div>
    {TABS.map((tab) => {
      const id = tablistId + '-' + tab;
      const active = tab === selected;
      return <div key={tab} id={id + '-panel'} role="tabpanel" aria-labelledby={id} tabIndex={0} hidden={!active}>
        {(active || visited.has(tab)) && groups[tab].content}
      </div>;
    })}
  </>;
}
