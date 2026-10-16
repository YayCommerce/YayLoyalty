/** Pure geometry for the SVG charts in chart.tsx: row values, axis ticks, curves and bar shapes. */

export type Point = [x: number, y: number];

/** A row's value for `key`, or undefined when the row has none. */
export function rowValue(row: object, key: string): number | undefined {
  const v = (row as Record<string, unknown>)[key];
  return v === undefined || v === null || v === '' ? undefined : Number(v);
}

/** Integer ticks from 0 that cover `max` in about `count` steps of 1, 2, 2.5 or 5 × 10ⁿ. */
export function niceTicks(max: number, count = 5): number[] {
  if (!(max > 0)) return [0];
  const raw = max / (count - 1);
  const pow = 10 ** Math.floor(Math.log10(raw));
  const f = raw / pow;
  const step = Math.max(1, Math.ceil((f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * pow));
  const ticks: number[] = [];
  for (let v = 0; v < max + step; v += step) ticks.push(v);
  return ticks;
}

/**
 * Which of `n` evenly spaced x labels to draw so none overlap, always keeping the last one.
 * `spacing` is the distance between neighbours, `labelWidth` the widest label plus the gap.
 */
export function xTickIndices(n: number, spacing: number, labelWidth: number): number[] {
  if (n === 0) return [];
  const step = spacing > 0 ? Math.max(1, Math.ceil(labelWidth / spacing)) : n;
  const indices: number[] = [];
  for (let i = n - 1; i >= 0; i -= step) indices.unshift(i);
  return indices;
}

/** A monotone cubic through `points` (sorted by x): no overshoot between points, like d3's curveMonotoneX. */
export function monotonePath(points: Point[]): string {
  const n = points.length;
  if (n === 0) return '';
  const start = `M${points[0][0]},${points[0][1]}`;
  if (n === 1) return start;
  if (n === 2) return `${start}L${points[1][0]},${points[1][1]}`;

  const h: number[] = [];
  const s: number[] = [];
  for (let i = 0; i < n - 1; i++) {
    h[i] = points[i + 1][0] - points[i][0];
    s[i] = h[i] ? (points[i + 1][1] - points[i][1]) / h[i] : 0;
  }
  const t: number[] = [];
  for (let i = 1; i < n - 1; i++) {
    const p = (s[i - 1] * h[i] + s[i] * h[i - 1]) / (h[i - 1] + h[i]);
    t[i] =
      (Math.sign(s[i - 1]) + Math.sign(s[i])) * Math.min(Math.abs(s[i - 1]), Math.abs(s[i]), 0.5 * Math.abs(p)) || 0;
  }
  t[0] = (3 * s[0] - t[1]) / 2;
  t[n - 1] = (3 * s[n - 2] - t[n - 2]) / 2;

  let d = start;
  for (let i = 0; i < n - 1; i++) {
    const [x0, y0] = points[i];
    const [x1, y1] = points[i + 1];
    const c = h[i] / 3;
    d += `C${x0 + c},${y0 + c * t[i]},${x1 - c},${y1 - c * t[i + 1]},${x1},${y1}`;
  }
  return d;
}

/** A bar from `bottom` up to `top`, its top corners rounded by `radius`. */
export function barPath(x: number, width: number, top: number, bottom: number, radius: number): string {
  const r = Math.max(0, Math.min(radius, width / 2, bottom - top));
  const x1 = x + width;
  return `M${x},${bottom}V${top + r}Q${x},${top},${x + r},${top}H${x1 - r}Q${x1},${top},${x1},${top + r}V${bottom}Z`;
}
