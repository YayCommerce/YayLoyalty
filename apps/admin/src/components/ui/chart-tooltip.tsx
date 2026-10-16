import type { ChartConfig, ChartFormat } from './chart';
import { rowValue } from './chart-geometry';

/** The hovered row's values beside the cursor at `x`, on its left when `flip` is set. */
export function ChartTooltip({
  config,
  row,
  title,
  format,
  x,
  flip,
  top,
}: {
  config: ChartConfig;
  row: object;
  title: string;
  format: ChartFormat;
  x: number;
  flip: boolean;
  top: number;
}) {
  return (
    <div
      className="border-border/50 bg-background pointer-events-none absolute z-10 grid min-w-32 gap-1.5 rounded-lg border px-2.5 py-1.5 shadow-xl"
      style={{ top, left: x + (flip ? -12 : 12), transform: flip ? 'translateX(-100%)' : undefined }}
    >
      <div className="font-medium">{title}</div>
      {Object.entries(config).map(([key, { label, color }]) => {
        const v = rowValue(row, key);
        return v === undefined ? null : (
          <div key={key} className="flex items-center gap-2">
            <span className="size-2.5 shrink-0 rounded-[2px]" style={{ backgroundColor: color }} />
            <span className="text-muted-foreground flex-1">{label}</span>
            <span className="font-mono font-medium tabular-nums">{format.value(v)}</span>
          </div>
        );
      })}
    </div>
  );
}

/** A swatch and label per series, centred under the chart. */
export function ChartLegend({ config }: { config: ChartConfig }) {
  return (
    <div className="flex flex-wrap items-center justify-center gap-4 pt-3">
      {Object.entries(config).map(([key, { label, color }]) => (
        <div key={key} className="flex items-center gap-1.5">
          <span className="size-2 shrink-0 rounded-[2px]" style={{ backgroundColor: color }} />
          {label}
        </div>
      ))}
    </div>
  );
}
