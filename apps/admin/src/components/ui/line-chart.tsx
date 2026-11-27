import { CartesianChart, ChartLegend, valueOf, type CartesianChartProps } from './chart';
import { monotonePath } from './chart.scale';

type LineChartProps<T extends object> = Omit<CartesianChartProps<T>, 'layout' | 'keys' | 'children'> & {
  series: { key: string; strokeWidth?: number }[];
  /** `'top'` puts the legend above the plot, right-aligned. */
  legend?: boolean | 'top';
};

/** Smooth lines over a category x axis; a row missing a series' value breaks that line. Place inside `ChartContainer`. */
export function LineChart<T extends object>({ series, legend = false, data, ...props }: LineChartProps<T>) {
  const keys = series.map((s) => s.key);

  return (
    <>
      {legend === 'top' && <ChartLegend keys={keys} className="flex-wrap justify-end pt-0 pb-3" />}
      <CartesianChart {...props} data={data} keys={keys} layout="point">
        {({ x, y }) =>
          series.map(({ key, strokeWidth = 2 }) => {
            const runs: [number, number][][] = [[]];
            data.forEach((row, i) => {
              const value = valueOf(row, key);
              if (value === undefined) runs.push([]);
              else runs[runs.length - 1].push([x(i), y(value)]);
            });
            return (
              <path
                key={key}
                d={runs.map(monotonePath).join('')}
                fill="none"
                stroke={`var(--color-${key})`}
                strokeWidth={strokeWidth}
              />
            );
          })
        }
      </CartesianChart>
      {legend === true && <ChartLegend keys={keys} />}
    </>
  );
}
