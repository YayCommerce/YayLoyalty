import { useLayoutEffect, useRef, useState, type PointerEvent, type ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { niceTicks, rowValue, xTickIndices } from './chart-geometry';
import { ChartLegend, ChartTooltip } from './chart-tooltip';

/**
 * A small SVG chart frame, in place of a charting library, to keep the admin bundle small.
 * It draws the axes, the grid and the hover cursor, with the tooltip and legend from `chart-tooltip.tsx`; `line-chart.tsx`
 * and `bar-chart.tsx` draw the marks through `children`. Rows are evenly spaced by index on x.
 */

export type ChartConfig = Record<string, { label: ReactNode; color: string }>;

export type ChartFormat = {
  /** An x value under the axis. */
  x: (value: string) => string;
  /** An x value as the tooltip's title. */
  tooltipX: (value: string) => string;
  /** A y axis tick. */
  y: (value: number) => string;
  /** A value in the tooltip. */
  value: (value: number) => ReactNode;
};

export type ChartGeometry = {
  x: (index: number) => number;
  y: (value: number) => number;
  /** Width of one row's slot on x. */
  band: number;
  bottom: number;
};

export type ChartFrameProps = {
  config: ChartConfig;
  data: readonly object[];
  xKey: string;
  format: ChartFormat;
  /** Height and width come from here, as a Tailwind class. */
  className?: string;
  /** An all-zero chart: a lone "0" tick, no tooltip. */
  empty?: boolean;
  grid?: boolean;
  legend?: boolean;
  /** A dashed horizontal line at this value. */
  referenceY?: number;
  minTickGap?: number;
};

const TOP = 8;
const RIGHT = 8;
const X_AXIS_HEIGHT = 30;
const Y_AXIS_WIDTH = 56;
/** Approximate width of one character of `text-xs`, to keep x labels apart without measuring them. */
const CHAR_WIDTH = 7;

function useElementSize<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [size, setSize] = useState({ width: 0, height: 0 });
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const observer = new ResizeObserver(([entry]) => {
      const { width, height } = entry.contentRect;
      setSize((prev) => (prev.width === width && prev.height === height ? prev : { width, height }));
    });
    observer.observe(el);
    return () => observer.disconnect();
  }, []);
  return [ref, size] as const;
}

export function ChartFrame({
  config,
  data,
  xKey,
  format,
  className,
  empty = false,
  grid = false,
  legend = false,
  referenceY,
  minTickGap = 24,
  layout,
  children,
}: ChartFrameProps & { layout: 'point' | 'band'; children: (geometry: ChartGeometry) => ReactNode }) {
  const [ref, { width, height }] = useElementSize<HTMLDivElement>();
  const [active, setActive] = useState<number | null>(null);
  const keys = Object.keys(config);

  const max = Math.max(0, ...data.flatMap((row) => keys.map((k) => rowValue(row, k) ?? 0)));
  const yTicks = empty ? [0] : niceTicks(max);
  const yMax = yTicks[yTicks.length - 1] || 1;

  const n = data.length;
  const left = Y_AXIS_WIDTH;
  const plotWidth = Math.max(0, width - left - RIGHT);
  const bottom = height - X_AXIS_HEIGHT;
  const band = n ? plotWidth / n : 0;
  const spacing = layout === 'band' ? band : n > 1 ? plotWidth / (n - 1) : plotWidth;
  const geometry: ChartGeometry = {
    x: (i) => (layout === 'band' ? left + band * (i + 0.5) : left + (n > 1 ? i * spacing : plotWidth / 2)),
    y: (v) => bottom - (v / yMax) * (bottom - TOP),
    band,
    bottom,
  };

  const xLabels = data.map((row) => format.x(String((row as Record<string, unknown>)[xKey])));
  const labelWidth = Math.max(0, ...xLabels.map((l) => l.length)) * CHAR_WIDTH + minTickGap;

  const onPointerMove = (e: PointerEvent<HTMLDivElement>) => {
    const px = e.clientX - e.currentTarget.getBoundingClientRect().left - left;
    if (empty || n === 0 || px < 0 || px > plotWidth) return setActive(null);
    const i = layout === 'band' ? Math.floor(px / band) : Math.round(px / (spacing || 1));
    setActive(Math.min(n - 1, Math.max(0, i)));
  };

  const activeRow = active === null ? undefined : data[active];

  return (
    <div className={cn('flex w-full flex-col text-xs', className)}>
      <div
        ref={ref}
        className="relative min-h-0 flex-1"
        onPointerMove={onPointerMove}
        onPointerLeave={() => setActive(null)}
      >
        {width > 0 && height > 0 && (
          <svg width={width} height={height} className="absolute inset-0 overflow-visible">
            {yTicks.map((t) => (
              <g key={t}>
                {grid && (
                  <line
                    x1={left}
                    x2={left + plotWidth}
                    y1={geometry.y(t)}
                    y2={geometry.y(t)}
                    className="stroke-border/50"
                  />
                )}
                <text
                  x={left - 8}
                  y={geometry.y(t)}
                  textAnchor="end"
                  dominantBaseline="middle"
                  className="fill-muted-foreground"
                >
                  {format.y(t)}
                </text>
              </g>
            ))}
            {xTickIndices(n, spacing, labelWidth).map((i) => (
              <text key={i} x={geometry.x(i)} y={bottom + 16} textAnchor="middle" className="fill-muted-foreground">
                {xLabels[i]}
              </text>
            ))}
            {active !== null &&
              (layout === 'band' ? (
                <rect
                  x={geometry.x(active) - band / 2}
                  y={TOP}
                  width={band}
                  height={bottom - TOP}
                  className="fill-muted"
                />
              ) : (
                <line x1={geometry.x(active)} x2={geometry.x(active)} y1={TOP} y2={bottom} className="stroke-border" />
              ))}
            {referenceY !== undefined && (
              <line
                x1={left}
                x2={left + plotWidth}
                y1={geometry.y(referenceY)}
                y2={geometry.y(referenceY)}
                stroke="var(--muted-foreground)"
                strokeDasharray="4 4"
              />
            )}
            {children(geometry)}
          </svg>
        )}
        {activeRow && active !== null && (
          <ChartTooltip
            config={config}
            row={activeRow}
            title={format.tooltipX(String((activeRow as Record<string, unknown>)[xKey]))}
            format={format}
            x={geometry.x(active)}
            flip={geometry.x(active) > width / 2}
            top={TOP}
          />
        )}
      </div>
      {legend && <ChartLegend config={config} />}
    </div>
  );
}
