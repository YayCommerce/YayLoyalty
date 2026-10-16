import { ChartFrame, type ChartFrameProps } from './chart';
import { monotonePath, rowValue, type Point } from './chart-geometry';

/**
 * One smooth line per key of `config`, in its colour. A row without a value for a key breaks
 * that line there. `strokeWidths` thins a line; the default is 2.
 */
export function LineChart({
  strokeWidths = {},
  ...frame
}: ChartFrameProps & { strokeWidths?: Record<string, number> }) {
  return (
    <ChartFrame {...frame} layout="point">
      {({ x, y }) =>
        Object.entries(frame.config).map(([key, { color }]) => {
          const segments: Point[][] = [[]];
          frame.data.forEach((row, i) => {
            const v = rowValue(row, key);
            if (v === undefined) segments.push([]);
            else segments[segments.length - 1].push([x(i), y(v)]);
          });
          return (
            <path
              key={key}
              d={segments.map(monotonePath).join('')}
              fill="none"
              stroke={color}
              strokeWidth={strokeWidths[key] ?? 2}
            />
          );
        })
      }
    </ChartFrame>
  );
}
