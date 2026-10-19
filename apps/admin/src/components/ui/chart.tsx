import * as React from 'react';

import { cn } from '@/lib/utils';
import { niceTicks, tickIndices } from './chart.scale';

/**
 * In-house SVG chart frame, shared by `line-chart.tsx` and `bar-chart.tsx`. It replaced recharts,
 * which was a quarter of the admin bundle for three charts.
 *
 * Series colours come from `ChartConfig` as `--color-<key>` variables scoped to the chart; the
 * dark-theme selector is `.yay-dark` (see theme-provider.tsx).
 */

// Format: { THEME_NAME: CSS_SELECTOR }
const THEMES = { light: '', dark: '.yay-dark' } as const;

export type ChartConfig = {
  [k in string]: {
    label?: React.ReactNode;
    icon?: React.ComponentType;
  } & ({ color?: string; theme?: never } | { color?: never; theme: Record<keyof typeof THEMES, string> });
};

const ChartContext = React.createContext<ChartConfig | null>(null);

function useChartConfig() {
  const config = React.useContext(ChartContext);

  if (!config) {
    throw new Error('useChartConfig must be used within a <ChartContainer />');
  }

  return config;
}

function ChartContainer({
  id,
  className,
  children,
  config,
  ...props
}: React.ComponentProps<'div'> & { config: ChartConfig }) {
  const uniqueId = React.useId();
  const chartId = `chart-${id || uniqueId.replace(/:/g, '')}`;

  return (
    <ChartContext.Provider value={config}>
      <div
        data-slot="chart"
        data-chart={chartId}
        className={cn('flex aspect-video flex-col text-xs', className)}
        {...props}
      >
        <ChartStyle id={chartId} config={config} />
        {children}
      </div>
    </ChartContext.Provider>
  );
}

const ChartStyle = ({ id, config }: { id: string; config: ChartConfig }) => {
  const colorConfig = Object.entries(config).filter(([, config]) => config.theme || config.color);

  if (!colorConfig.length) {
    return null;
  }

  return (
    <style
      dangerouslySetInnerHTML={{
        __html: Object.entries(THEMES)
          .map(
            ([theme, prefix]) => `
${prefix} [data-chart=${id}] {
${colorConfig
  .map(([key, itemConfig]) => {
    const color = itemConfig.theme?.[theme as keyof typeof itemConfig.theme] || itemConfig.color;
    return color ? `  --color-${key}: ${color};` : null;
  })
  .join('\n')}
}
`,
          )
          .join('\n'),
      }}
    />
  );
};

function ChartSwatch({ dataKey, className }: { dataKey: string; className?: string }) {
  return (
    <div className={cn('shrink-0 rounded-[2px]', className)} style={{ backgroundColor: `var(--color-${dataKey})` }} />
  );
}

function ChartLegend({ keys }: { keys: string[] }) {
  const config = useChartConfig();

  return (
    <div className="flex items-center justify-center gap-4 pt-3">
      {keys.map((key) => (
        <div key={key} className="flex items-center gap-1.5">
          <ChartSwatch dataKey={key} className="size-2" />
          {config[key]?.label}
        </div>
      ))}
    </div>
  );
}

/** A row's numeric value for `key`, or undefined when it has none. */
function valueOf(row: object, key: string): number | undefined {
  const value = (row as Record<string, unknown>)[key];
  return typeof value === 'number' ? value : undefined;
}

function useSize() {
  const ref = React.useRef<HTMLDivElement>(null);
  const [size, setSize] = React.useState({ width: 0, height: 0 });

  React.useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const observer = new ResizeObserver(([entry]) =>
      setSize({ width: entry.contentRect.width, height: entry.contentRect.height }),
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  return [ref, size] as const;
}

const Y_AXIS_WIDTH = 56;
const X_AXIS_HEIGHT = 30;
const MARGIN = { top: 8, right: 8 };
/** Rough width of one `text-xs` character, for spacing the x labels. */
const CHAR_WIDTH = 6.5;

export type ChartScales = {
  x: (index: number) => number;
  y: (value: number) => number;
  /** Width of one category: the bar slot in a band layout, the gap between points otherwise. */
  band: number;
};

export type CartesianChartProps<T extends object> = {
  data: T[];
  xKey: keyof T & string;
  /** Series named in the tooltip, in order. */
  keys: string[];
  /** `point` puts the first and last rows on the edges, as a line chart does; `band` centres each row in a slot. */
  layout: 'point' | 'band';
  /** All zero: a lone `0` tick, no tooltip; the caller draws its own overlay. */
  empty?: boolean;
  grid?: boolean;
  /** A dashed horizontal line at this value. */
  referenceY?: number;
  minTickGap?: number;
  xTickFormat: (value: string) => string;
  yTickFormat: (value: number) => string;
  tooltipLabel: (value: string) => React.ReactNode;
  tooltipValue: (value: number) => React.ReactNode;
  children: (scales: ChartScales) => React.ReactNode;
};

function CartesianChart<T extends object>({
  data,
  xKey,
  keys,
  layout,
  empty = false,
  grid = false,
  referenceY,
  minTickGap = 5,
  xTickFormat,
  yTickFormat,
  tooltipLabel,
  tooltipValue,
  children,
}: CartesianChartProps<T>) {
  const [ref, { width, height }] = useSize();
  const [hover, setHover] = React.useState<{ index: number; px: number; py: number } | null>(null);

  const left = Y_AXIS_WIDTH;
  const right = width - MARGIN.right;
  const top = MARGIN.top;
  const bottom = height - X_AXIS_HEIGHT;
  const count = data.length;

  const max = Math.max(referenceY ?? 0, ...data.flatMap((row) => keys.map((key) => valueOf(row, key) ?? 0)));
  const yTicks = empty ? [0] : niceTicks(max);
  const yTop = yTicks[yTicks.length - 1] || 1;

  const band = layout === 'band' ? (right - left) / Math.max(count, 1) : (right - left) / Math.max(count - 1, 1);
  const scales: ChartScales = {
    x: (i) => (layout === 'band' ? left + (i + 0.5) * band : count === 1 ? (left + right) / 2 : left + i * band),
    y: (v) => bottom - (v / yTop) * (bottom - top),
    band,
  };

  const xLabels = data.map((row) => xTickFormat(String((row as Record<string, unknown>)[xKey])));
  const labelWidth = Math.max(0, ...xLabels.map((label) => label.length)) * CHAR_WIDTH;

  const onPointerMove = (e: React.PointerEvent<SVGSVGElement>) => {
    if (empty || !count) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const px = e.clientX - rect.left;
    const raw = layout === 'band' ? Math.floor((px - left) / band) : Math.round((px - left) / band);
    setHover({ index: Math.min(count - 1, Math.max(0, raw)), px, py: e.clientY - rect.top });
  };

  const hovered = hover ? data[hover.index] : undefined;

  return (
    <div ref={ref} className="relative min-h-0 flex-1">
      {width > 0 && height > 0 && (
        <svg
          width={width}
          height={height}
          className="overflow-visible"
          onPointerMove={onPointerMove}
          onPointerLeave={() => setHover(null)}
        >
          {grid &&
            yTicks.map((t) => (
              <line key={t} x1={left} x2={right} y1={scales.y(t)} y2={scales.y(t)} className="stroke-border/50" />
            ))}
          {hover &&
            (layout === 'band' ? (
              <rect
                x={scales.x(hover.index) - band / 2}
                y={top}
                width={band}
                height={bottom - top}
                className="fill-muted"
              />
            ) : (
              <line
                x1={scales.x(hover.index)}
                x2={scales.x(hover.index)}
                y1={top}
                y2={bottom}
                className="stroke-border"
              />
            ))}
          {children(scales)}
          {referenceY !== undefined && (
            <line
              x1={left}
              x2={right}
              y1={scales.y(referenceY)}
              y2={scales.y(referenceY)}
              stroke="var(--muted-foreground)"
              strokeDasharray="4 4"
            />
          )}
          {yTicks.map((t) => (
            <text key={t} x={left - 8} y={scales.y(t)} dy="0.355em" textAnchor="end" className="fill-muted-foreground">
              {yTickFormat(t)}
            </text>
          ))}
          {tickIndices(count, band, labelWidth, minTickGap).map((i) => (
            <text
              key={i}
              x={scales.x(i)}
              y={bottom + 8}
              dy="0.71em"
              textAnchor="middle"
              className="fill-muted-foreground"
            >
              {xLabels[i]}
            </text>
          ))}
        </svg>
      )}
      {hover && hovered && (
        <ChartTooltip
          px={hover.px}
          py={hover.py}
          flip={hover.px > width / 2}
          label={tooltipLabel(String((hovered as Record<string, unknown>)[xKey]))}
          rows={keys.flatMap((key) => {
            const value = valueOf(hovered, key);
            return value === undefined ? [] : [{ key, value: tooltipValue(value) }];
          })}
        />
      )}
    </div>
  );
}

function ChartTooltip({
  px,
  py,
  flip,
  label,
  rows,
}: {
  px: number;
  py: number;
  flip: boolean;
  label: React.ReactNode;
  rows: { key: string; value: React.ReactNode }[];
}) {
  const config = useChartConfig();

  return (
    <div
      className="border-border/50 bg-background pointer-events-none absolute z-10 grid min-w-32 gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs shadow-xl"
      style={{ left: px, top: py, transform: flip ? 'translate(calc(-100% - 12px), 12px)' : 'translate(12px, 12px)' }}
    >
      <div className="font-medium">{label}</div>
      <div className="grid gap-1.5">
        {rows.map(({ key, value }) => (
          <div key={key} className="flex items-center gap-2">
            <ChartSwatch dataKey={key} className="size-2.5" />
            <div className="flex flex-1 items-center justify-between gap-4 leading-none">
              <span className="text-muted-foreground whitespace-nowrap">{config[key]?.label ?? key}</span>
              <span className="text-foreground font-mono font-medium tabular-nums">{value}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

export { CartesianChart, ChartContainer, ChartLegend, ChartStyle, valueOf };
