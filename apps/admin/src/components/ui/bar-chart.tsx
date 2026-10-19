import { CartesianChart, valueOf, type CartesianChartProps } from './chart';
import { barPath } from './chart.scale';

/** Share of each slot a bar fills, as recharts' default 10% category gap gives. */
const BAR_FILL = 0.8;

type BarChartProps<T extends object> = Omit<CartesianChartProps<T>, 'layout' | 'keys' | 'children'> & {
  yKey: string;
  radius?: number;
  barOpacity?: (row: T) => number;
  /** Prints each bar's value above it. */
  topLabel?: (value: number) => string;
};

/** One bar per row. Place inside `ChartContainer`. */
export function BarChart<T extends object>({
  yKey,
  radius = 0,
  barOpacity,
  topLabel,
  data,
  ...props
}: BarChartProps<T>) {
  return (
    <CartesianChart {...props} data={data} keys={[yKey]} layout="band">
      {({ x, y, band }) =>
        data.map((row, i) => {
          const value = valueOf(row, yKey) ?? 0;
          const width = band * BAR_FILL;
          return (
            <g key={i}>
              <path
                d={barPath(x(i) - width / 2, y(value), width, y(0) - y(value), radius)}
                fill={`var(--color-${yKey})`}
                fillOpacity={barOpacity?.(row)}
              />
              {topLabel && (
                <text x={x(i)} y={y(value) - 5} textAnchor="middle" className="fill-foreground text-xs">
                  {topLabel(value)}
                </text>
              )}
            </g>
          );
        })
      }
    </CartesianChart>
  );
}
