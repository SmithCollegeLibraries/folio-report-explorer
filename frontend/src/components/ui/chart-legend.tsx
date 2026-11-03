import type { ReactNode } from 'react';
import { cn } from '../../lib/utils';

export type ChartLegendEntry = { value?: string; color?: string; dataKey?: string; payload?: { fill?: string } };

export function ChartLegendContent({
  payload, className, name, value,
}: {
  payload?: ChartLegendEntry[];
  className?: string;
  name?: (entry: ChartLegendEntry) => ReactNode;
  value?: (entry: ChartLegendEntry) => ReactNode;
}) {
  if (!payload?.length) return null;
  return <ul className={cn('flex flex-wrap justify-center gap-x-5 gap-y-2 pt-3 text-xs text-gray-700', className)}>
    {payload.map((entry, index) => <li key={`${entry.dataKey ?? entry.value}-${index}`} className="recharts-legend-item flex items-center gap-2">
      <svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12">
        <rect width="12" height="12" rx="2" fill={entry.color ?? entry.payload?.fill ?? '#6b7280'} />
      </svg>
      <span className="recharts-legend-item-text">{name ? name(entry) : entry.value}</span>
      {value && <span className="text-gray-500">{value(entry)}</span>}
    </li>)}
  </ul>;
}
