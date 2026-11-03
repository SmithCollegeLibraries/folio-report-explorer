import type { ReactNode } from 'react';
import { cn } from '../../lib/utils';
import { useChart } from './chart';

export type ChartTooltipEntry = {
  name?: string;
  dataKey?: string;
  value?: number | string;
  color?: string;
  payload?: Record<string, unknown>;
};

export function ChartTooltipContent({
  active, payload, label, formatter, className,
}: {
  active?: boolean;
  payload?: ChartTooltipEntry[];
  label?: ReactNode;
  formatter?: (entry: ChartTooltipEntry) => ReactNode;
  className?: string;
}) {
  const { config } = useChart();
  if (!active || !payload?.length) return null;
  return <div className={cn('grid min-w-32 gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm shadow-md', className)}>
    {label !== undefined && <p className="font-medium text-gray-900">{label}</p>}
    {payload.map((entry, index) => {
      const key = String(entry.dataKey ?? entry.name ?? index);
      const labelText = config[key]?.label ?? entry.name ?? key;
      return <div key={`${key}-${index}`} className="flex items-center gap-2 text-gray-700">
        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: entry.color ?? config[key]?.color }} />
        {formatter ? formatter(entry) : <><span>{labelText}</span><span className="ml-auto font-mono">{entry.value}</span></>}
      </div>;
    })}
  </div>;
}
