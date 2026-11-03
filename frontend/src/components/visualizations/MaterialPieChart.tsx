import { useMemo, useState } from 'react';
import { ChartContainer, type ChartConfig } from '../ui/chart';
import { ChartLegendContent, type ChartLegendEntry } from '../ui/chart-legend';
import { amount } from './ChartCard';
import { useNarrowViewport } from './useNarrowViewport';

type MaterialItem = { materialType: string | null; amount: number };
type PieSlice = { key: string; name: string; amount: number; percentage: number };
const OTHER_KEY = '__rpt022_all_other_materials__';
const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)', 'var(--chart-6)'];
const NEUTRAL = 'var(--chart-neutral)';
const PREFERRED = ['E-Journal Package', 'Database', 'E-Book', 'E-Journal', 'Book', 'E-Book Package'];

function roundShares(values: number[]) {
  if (!values.length) return [];
  const total = values.reduce((sum, value) => sum + value, 0);
  const units = values.map((value) => value / total * 1000);
  const rounded = units.map(Math.floor);
  const remainder = 1000 - rounded.reduce((sum, value) => sum + value, 0);
  const order = units.map((value, index) => ({ index, fraction: value - rounded[index] }))
    .sort((a, b) => b.fraction - a.fraction || a.index - b.index);
  for (let index = 0; index < remainder; index += 1) rounded[order[index].index] += 1;
  return rounded.map((value) => value / 10);
}

function stableHash(value: string) {
  let hash = 0;
  for (const character of value) hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
  return hash % COLORS.length;
}

function materialColors(items: MaterialItem[]) {
  const names = [...new Set(items.filter((item) => item.amount > 0).map((item) => item.materialType || 'No material type'))];
  const ordered = names.sort((a, b) => {
    const ai = PREFERRED.indexOf(a); const bi = PREFERRED.indexOf(b);
    if (ai >= 0 || bi >= 0) return (ai < 0 ? 99 : ai) - (bi < 0 ? 99 : bi);
    return a.localeCompare(b);
  });
  const colors = new Map<string, string>();
  const used = new Set<number>();
  for (const name of ordered) {
    const preferredIndex = PREFERRED.indexOf(name);
    let colorIndex = preferredIndex >= 0 ? preferredIndex : stableHash(name);
    while (used.has(colorIndex) && used.size < COLORS.length) colorIndex = (colorIndex + 1) % COLORS.length;
    colors.set(name, COLORS[colorIndex]);
    used.add(colorIndex);
  }
  return colors;
}

function buildSlices(items: MaterialItem[]) {
  const positive = items.map((item) => ({ ...item, name: item.materialType || 'No material type' }))
    .filter((item) => item.amount > 0)
    .sort((a, b) => b.amount - a.amount || a.name.localeCompare(b.name));
  const top = positive.slice(0, 6).map((item) => ({ key: item.name, name: item.name, amount: item.amount, percentage: 0 }));
  const remainder = positive.slice(6).reduce((sum, item) => sum + item.amount, 0);
  if (remainder > 0) top.push({ key: OTHER_KEY, name: 'All other types', amount: remainder, percentage: 0 });
  const shares = roundShares(top.map((item) => item.amount));
  return top.map((item, index) => ({ ...item, percentage: shares[index] }));
}

function materialTableRows(items: MaterialItem[]) {
  const positiveTotal = items.reduce((sum, item) => sum + Math.max(item.amount, 0), 0);
  return items.map((item) => ({
    name: item.materialType || 'No material type', amount: item.amount,
    percentage: positiveTotal ? item.amount / positiveTotal * 100 : 0,
  }));
}

export function MaterialPieChart({ items }: { items: MaterialItem[] }) {
  const narrow = useNarrowViewport();
  const [active, setActive] = useState<PieSlice | null>(null);

  const slices = useMemo(() => buildSlices(items), [items]);
  const colors = useMemo(() => materialColors(slices
    .filter((slice) => slice.key !== OTHER_KEY)
    .map((slice) => ({ materialType: slice.name, amount: slice.amount }))), [slices]);
  const config: ChartConfig = Object.fromEntries(slices.map((slice, index) => [
    slice.key, { label: slice.name, color: slice.key === OTHER_KEY ? NEUTRAL : colors.get(slice.name) ?? COLORS[index % COLORS.length] },
  ]));
  const legendPayload: ChartLegendEntry[] = slices.map((slice) => ({
    value: slice.name, dataKey: slice.key, color: slice.key === OTHER_KEY ? NEUTRAL : colors.get(slice.name),
  }));
  const total = slices.reduce((sum, slice) => sum + slice.amount, 0);
  const center = { x: 180, y: 142 };
  const radius = 88;
  const labels = slices.map((slice, index) => {
    const prior = slices.slice(0, index).reduce((sum, item) => sum + item.amount, 0);
    const angle = (prior + slice.amount / 2) / total * Math.PI * 2 - Math.PI / 2;
    return { slice, angle, side: Math.cos(angle) >= 0 ? 'right' : 'left', y: center.y + Math.sin(angle) * 120 };
  });
  for (const side of ['left', 'right']) {
    const sideLabels = labels.filter((label) => label.side === side && label.slice.percentage >= 3).sort((a, b) => a.y - b.y);
    sideLabels.forEach((label, index) => {
      label.y = Math.max(label.y, index === 0 ? 24 : sideLabels[index - 1].y + 22);
    });
    if (sideLabels.length) {
      const overflow = sideLabels[sideLabels.length - 1].y - 298;
      if (overflow > 0) sideLabels.forEach((label) => { label.y -= overflow; });
    }
  }

  return <div className="space-y-2">
    <ChartContainer config={config} className="h-80" aria-label="Paid purchases by material type">
      <svg className="recharts-surface h-full w-full" viewBox="0 0 360 320" role="img" aria-label="Paid purchases by material type">
        <g className="recharts-pie-sectors">
          {slices.map((slice, index) => {
            const start = (slices.slice(0, index).reduce((sum, item) => sum + item.amount, 0) / total) * Math.PI * 2 - Math.PI / 2;
            const end = start + slice.amount / total * Math.PI * 2;
            const point = (angle: number) => [center.x + Math.cos(angle) * radius, center.y + Math.sin(angle) * radius];
            const [x1, y1] = point(start); const [x2, y2] = point(end);
            const large = end - start > Math.PI ? 1 : 0;
            const fill = slice.key === OTHER_KEY ? NEUTRAL : colors.get(slice.name) ?? COLORS[index % COLORS.length];
            return <path key={`${slice.key}-${index}`} className="recharts-pie-sector" d={`M ${center.x} ${center.y} L ${x1} ${y1} A ${radius} ${radius} 0 ${large} 1 ${x2} ${y2} Z`}
              fill={fill} stroke="white" strokeWidth="2" tabIndex={0} role="img"
              aria-label={`${slice.name}, ${amount(slice.amount)}, ${slice.percentage.toFixed(1)}%`}
              onMouseEnter={() => setActive(slice)} onFocus={() => setActive(slice)} onMouseLeave={() => setActive(null)} />;
          })}
        </g>
        {!narrow && <g className="recharts-pie-labels">
          {labels.filter((label) => label.slice.percentage >= 3).map(({ slice, angle, side, y }) => {
            const [x1, y1] = [center.x + Math.cos(angle) * radius, center.y + Math.sin(angle) * radius];
            const x2 = side === 'right' ? 326 : 34;
            const x3 = side === 'right' ? 332 : 28;
            return <g key={slice.key}>
              <path d={`M ${x1} ${y1} L ${x2} ${y} L ${x3} ${y}`} fill="none" stroke="#6b7280" strokeWidth="1" />
              <text x={x3} y={y + 4} textAnchor={side === 'right' ? 'start' : 'end'} fill="#374151" fontSize="10">
                {slice.name} {slice.percentage.toFixed(1)}%
              </text>
            </g>;
          })}
        </g>}
      </svg>
    </ChartContainer>
    {active && <p role="status" className="text-center text-sm text-gray-700">{active.name}: {amount(active.amount)} ({active.percentage.toFixed(1)}%)</p>}
    <ChartLegendContent payload={legendPayload} value={narrow ? (entry) => {
      const slice = slices.find((item) => item.name === entry.value);
      return slice ? `${slice.percentage.toFixed(1)}%` : undefined;
    } : undefined} className="pt-1" />
  </div>;
}

export function materialRows(items: MaterialItem[]) {
  return materialTableRows(items).sort((a, b) => b.amount - a.amount || a.name.localeCompare(b.name));
}
