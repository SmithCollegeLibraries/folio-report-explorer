import { useEffect, useMemo, useState } from 'react';
import type { LegendPayload } from 'recharts';
import { ChartContainer, type ChartConfig } from '../ui/chart';
import { ChartLegendContent } from '../ui/chart-legend';
import { amount } from './ChartCard';
import { useNarrowViewport } from './useNarrowViewport';

type MaterialItem = { materialType: string | null; amount: number };
type PieSlice = { key: string; name: string; amount: number; percentage: number };
const OTHER_KEY = '__rpt022_all_other_materials__';
const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)', 'var(--chart-6)'];
const NEUTRAL = 'var(--chart-neutral)';
const PREFERRED = ['E-Journal Package', 'Database', 'E-Book', 'E-Journal', 'Book', 'E-Book Package'];

function materialLegendIcon(color: string) {
  return function MaterialLegendIcon() {
    return <svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12">
      <rect width="12" height="12" rx="2" fill={color} />
    </svg>;
  };
}

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

function measuredTextWidth(value: string) {
  const fallback = Array.from(value).length * 6;
  if (typeof document === 'undefined' || typeof CanvasRenderingContext2D === 'undefined') return fallback;

  try {
    const context = document.createElement('canvas').getContext('2d');
    if (!context) return fallback;
    context.font = '10px sans-serif';
    const width = context.measureText(value).width;
    return Number.isFinite(width) && width > 0 ? width : fallback;
  } catch {
    return fallback;
  }
}

function fitSideLabel(name: string, shareText: string, availableWidth: number) {
  const fullLabel = `${name} ${shareText}`;
  if (measuredTextWidth(fullLabel) <= availableWidth) return fullLabel;

  const characters = Array.from(name);
  let low = 0;
  let high = characters.length;
  while (low < high) {
    const middle = Math.ceil((low + high) / 2);
    const candidate = `${characters.slice(0, middle).join('')}… ${shareText}`;
    if (measuredTextWidth(candidate) <= availableWidth) low = middle;
    else high = middle - 1;
  }

  const fittedLabel = `${characters.slice(0, low).join('')}… ${shareText}`;
  if (measuredTextWidth(fittedLabel) <= availableWidth) return fittedLabel;
  return measuredTextWidth(shareText) <= availableWidth ? shareText : '';
}

export function MaterialPieChart({ items }: { items: MaterialItem[] }) {
  const viewportNarrow = useNarrowViewport();
  const [chartElement, setChartElement] = useState<HTMLDivElement | null>(null);
  const [chartSize, setChartSize] = useState({ width: 0, height: 320 });
  const [active, setActive] = useState<PieSlice | null>(null);

  useEffect(() => {
    if (!chartElement) return;
    const measure = (width: number, height: number) => {
      if (width > 0 && height > 0) {
        setChartSize((current) => current.width === width && current.height === height
          ? current : { width, height });
      }
    };
    const bounds = chartElement.getBoundingClientRect();
    measure(bounds.width, bounds.height);
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) => measure(entry.contentRect.width, entry.contentRect.height));
    observer.observe(chartElement);
    return () => observer.disconnect();
  }, [chartElement]);

  const narrow = viewportNarrow || chartSize.width < 480;

  const slices = useMemo(() => buildSlices(items), [items]);
  const colors = useMemo(() => materialColors(slices
    .filter((slice) => slice.key !== OTHER_KEY)
    .map((slice) => ({ materialType: slice.name, amount: slice.amount }))), [slices]);
  const config: ChartConfig = Object.fromEntries(slices.map((slice, index) => [
    `slice-${index}`, (() => {
      const color = slice.key === OTHER_KEY ? NEUTRAL : colors.get(slice.name) ?? COLORS[index % COLORS.length];
      return {
        label: narrow ? `${slice.name} ${slice.percentage.toFixed(1)}%` : slice.name,
        color,
        icon: materialLegendIcon(color),
      };
    })(),
  ]));
  const legendPayload: LegendPayload[] = slices.map((slice, index) => ({
    value: slice.name,
    dataKey: `slice-${index}`,
    color: slice.key === OTHER_KEY ? NEUTRAL : colors.get(slice.name) ?? COLORS[index % COLORS.length],
  }));
  const total = slices.reduce((sum, slice) => sum + slice.amount, 0);
  if (slices.length === 0) {
    return <p role="status" className="py-16 text-center text-gray-600">No positive purchases for this fiscal year</p>;
  }
  const width = chartSize.width || 320;
  const height = chartSize.height || 320;
  const center = { x: width / 2, y: height / 2 };
  const labelGap = 12;
  const labelPadding = 16;
  const maxRadius = Math.max(0, Math.min(
    height / 2 - 16,
    width / 2 - (narrow ? labelPadding : labelGap + labelPadding),
  ));
  const availableLabelWidth = narrow
    ? 0
    : Math.max(0, width / 2 - maxRadius - labelGap - labelPadding);
  const labelData = slices.map((slice, index) => {
    const prior = slices.slice(0, index).reduce((sum, item) => sum + item.amount, 0);
    const angle = (prior + slice.amount / 2) / total * Math.PI * 2 - Math.PI / 2;
    const side = Math.cos(angle) >= 0 ? 'right' : 'left';
    const shareText = `${slice.percentage.toFixed(1)}%`;
    const text = narrow ? '' : fitSideLabel(slice.name, shareText, availableLabelWidth);
    return { slice, angle, side, text, textWidth: text ? measuredTextWidth(text) : 0 };
  });
  const widestLabel = Math.max(0, ...labelData
    .filter(({ slice }) => slice.percentage >= 3)
    .map(({ textWidth }) => textWidth));
  const radius = narrow ? maxRadius : Math.max(0, Math.min(
    maxRadius,
    width / 2 - widestLabel - labelGap - labelPadding,
  ));
  const labels = labelData.map((label) => ({
    ...label,
    y: center.y + Math.sin(label.angle) * radius,
  }));
  for (const side of ['left', 'right']) {
    const sideLabels = labels.filter((label) => label.side === side && label.slice.percentage >= 3).sort((a, b) => a.y - b.y);
    sideLabels.forEach((label, index) => {
      label.y = Math.max(label.y, index === 0 ? 24 : sideLabels[index - 1].y + 22);
    });
    if (sideLabels.length) {
      const overflow = sideLabels[sideLabels.length - 1].y - (height - 24);
      if (overflow > 0) sideLabels.forEach((label) => { label.y -= overflow; });
    }
  }

  return <div className="space-y-2">
    <ChartContainer ref={setChartElement} config={config} className="h-80 min-w-0 w-full" aria-label="Paid purchases by material type">
      <div className="flex h-full w-full flex-col">
      <svg className="recharts-surface min-h-0 min-w-0 w-full flex-1"
        viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="xMidYMid meet"
        role="img" aria-label="Paid purchases by material type">
        <g className="recharts-pie-sectors">
          {slices.map((slice, index) => {
            const start = (slices.slice(0, index).reduce((sum, item) => sum + item.amount, 0) / total) * Math.PI * 2 - Math.PI / 2;
            const end = start + slice.amount / total * Math.PI * 2;
            const point = (angle: number) => [center.x + Math.cos(angle) * radius, center.y + Math.sin(angle) * radius];
            const [x1, y1] = point(start); const [x2, y2] = point(end);
            const large = end - start > Math.PI ? 1 : 0;
            const fill = slice.key === OTHER_KEY ? NEUTRAL : colors.get(slice.name) ?? COLORS[index % COLORS.length];
            const d = slices.length === 1
              ? `M ${center.x} ${center.y} L ${center.x} ${center.y - radius} A ${radius} ${radius} 0 1 1 ${center.x} ${center.y + radius} A ${radius} ${radius} 0 1 1 ${center.x} ${center.y - radius} Z`
              : `M ${center.x} ${center.y} L ${x1} ${y1} A ${radius} ${radius} 0 ${large} 1 ${x2} ${y2} Z`;
            return <path key={`${slice.key}-${index}`} className="recharts-pie-sector" d={d}
              fill={fill} stroke="white" strokeWidth="2" tabIndex={0} role="img"
              aria-label={`${slice.name}, ${amount(slice.amount)}, ${slice.percentage.toFixed(1)}%`}
              onMouseEnter={() => setActive(slice)} onFocus={() => setActive(slice)} onMouseLeave={() => setActive(null)} />;
          })}
        </g>
        {!narrow && <g className="recharts-pie-labels">
          {labels.filter((label) => label.slice.percentage >= 3).map(({ slice, angle, side, y, text }) => {
            const [x1, y1] = [center.x + Math.cos(angle) * radius, center.y + Math.sin(angle) * radius];
            const labelX = side === 'right' ? center.x + radius + labelGap : center.x - radius - labelGap;
            const lineEndX = side === 'right' ? labelX - 4 : labelX + 4;
            const elbowX = side === 'right' ? lineEndX - 8 : lineEndX + 8;
            return <g key={slice.key}>
              <path d={`M ${x1} ${y1} L ${elbowX} ${y} L ${lineEndX} ${y}`} fill="none" stroke="#6b7280" strokeWidth="1" />
              <text x={labelX} y={y + 4} textAnchor={side === 'right' ? 'start' : 'end'} fill="#374151" fontSize="10">
                {text}
              </text>
            </g>;
          })}
        </g>}
      </svg>
      <ChartLegendContent payload={legendPayload} verticalAlign="bottom"
        className={narrow ? 'flex-col items-start gap-2 pt-1' : 'flex-wrap gap-4 pt-1'} />
      </div>
    </ChartContainer>
    {active && <p role="status" className="text-center text-sm text-gray-700">{active.name}: {amount(active.amount)} ({active.percentage.toFixed(1)}%)</p>}
  </div>;
}

export function materialRows(items: MaterialItem[]) {
  return materialTableRows(items).sort((a, b) => b.amount - a.amount || a.name.localeCompare(b.name));
}
