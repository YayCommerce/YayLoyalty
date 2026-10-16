import { ChartFrame, type ChartFrameProps } from './chart';
import { barPath, rowValue } from './chart-geometry';

/** Share of each row's slot the bar fills. */
const BAR_FILL = 0.8;

/**
 * One bar per row for the single key of `config`, top corners rounded by `radius`.
 * `opacity` dims chosen rows; `label` prints a value above each bar.
 */
export function BarChart<Row extends object>({
  radius = 2,
  opacity,
  label,
  data,
  ...frame
}: Omit<ChartFrameProps, 'data'> & {
  data: readonly Row[];
  radius?: number;
  opacity?: (row: Row) => number;
  label?: (value: number) => string;
}) {
  const [key, { color }] = Object.entries(frame.config)[0];

  return (
    <ChartFrame {...frame} data={data} layout="band">
      {({ x, y, band, bottom }) =>
        data.map((row, i) => {
          const v = rowValue(row, key) ?? 0;
          if (v <= 0) return null;
          const width = band * BAR_FILL;
          return (
            <g key={i}>
              <path
                d={barPath(x(i) - width / 2, width, y(v), bottom, radius)}
                fill={color}
                fillOpacity={opacity?.(row) ?? 1}
              />
              {label && (
                <text x={x(i)} y={y(v) - 5} textAnchor="middle" className="fill-foreground">
                  {label(v)}
                </text>
              )}
            </g>
          );
        })
      }
    </ChartFrame>
  );
}
