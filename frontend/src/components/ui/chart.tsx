/*
 * Folio chart primitives adapted to shadcn/ui's New York, Tailwind CSS v3 chart conventions.
 * Source candidate: https://raw.githubusercontent.com/shadcn-ui/ui/v3.3.0/apps/www/registry/new-york/ui/chart.tsx
 * The source candidate returned HTTP 404. No upstream source file was copied; exact commit SHA is unresolved.
 * Copyright (c) 2023 shadcn
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

import { createContext, useContext, type ComponentType, type CSSProperties, type ReactNode } from 'react';
import { cn } from '../../lib/utils';

export type ChartConfig = Record<string, {
  label?: ReactNode;
  color?: string;
  icon?: ComponentType;
}>;

type ChartContextValue = { config: ChartConfig };
const ChartContext = createContext<ChartContextValue | null>(null);

export function useChart() {
  const context = useContext(ChartContext);
  if (!context) throw new Error('useChart must be used within a ChartContainer');
  return context;
}

export function ChartContainer({
  config, className, children, id, style, ...props
}: {
  config: ChartConfig;
  className?: string;
  children: ReactNode;
  id?: string;
  style?: CSSProperties;
  'aria-label'?: string;
}) {
  const variables = Object.fromEntries(Object.entries(config).flatMap(([key, value]) =>
    value.color ? [[`--color-${key}`, value.color]] : [])) as CSSProperties;
  return <ChartContext.Provider value={{ config }}>
    <div {...props} id={id} style={{ ...variables, ...style }}
      className={cn('relative w-full text-xs [&_.recharts-cartesian-axis-tick_text]:fill-gray-500 [&_.recharts-cartesian-grid_line]:stroke-gray-200 [&_.recharts-tooltip-cursor]:fill-gray-100 [&_.recharts-dot]:stroke-transparent [&_.recharts-layer]:outline-none [&_.recharts-polar-grid_[stroke]]:stroke-gray-200', className)}>
      {children}
    </div>
  </ChartContext.Provider>;
}

export function ChartStyle({ config }: { config: ChartConfig }) {
  const rules = Object.entries(config).filter(([, item]) => item.color)
    .map(([key, item]) => `[data-chart] { --color-${key}: ${item.color}; }`).join('\n');
  return <style>{rules}</style>;
}
