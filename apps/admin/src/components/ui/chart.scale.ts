/** Integer ticks from 0 with a 1/2/5 × 10ⁿ step, the last one at or above `max`. */
export function niceTicks(max: number, count = 5): number[] {
  if (max <= 0) return [0];
  const raw = max / (count - 1);
  const mag = 10 ** Math.floor(Math.log10(raw));
  const step = Math.max(1, Math.ceil(([1, 2, 5, 10].find((f) => f * mag >= raw) ?? 10) * mag));
  const ticks: number[] = [];
  for (let t = 0; t < max + step; t += step) ticks.push(t);
  return ticks;
}

/** Which of `count` labels to draw so none come closer than `minGap`; the last one always shows. */
export function tickIndices(count: number, slot: number, labelWidth: number, minGap: number): number[] {
  const every = Math.max(1, Math.ceil((labelWidth + minGap) / Math.max(slot, 1)));
  const indices: number[] = [];
  for (let i = 0; i < count; i++) {
    if ((count - 1 - i) % every === 0) indices.push(i);
  }
  return indices;
}

/** Monotone cubic path through points sorted by x, the curve recharts draws for `type="monotone"`. */
export function monotonePath(points: [number, number][]): string {
  const n = points.length;
  if (n === 0) return '';
  if (n < 3) return points.map(([x, y], i) => `${i ? 'L' : 'M'}${x},${y}`).join('');

  const dx: number[] = [];
  const slope: number[] = [];
  for (let i = 0; i < n - 1; i++) {
    dx[i] = points[i + 1][0] - points[i][0];
    slope[i] = dx[i] ? (points[i + 1][1] - points[i][1]) / dx[i] : 0;
  }

  const tangent: number[] = [slope[0]];
  for (let i = 1; i < n - 1; i++) {
    tangent[i] =
      slope[i - 1] * slope[i] <= 0
        ? 0
        : (3 * (dx[i - 1] + dx[i])) / ((2 * dx[i] + dx[i - 1]) / slope[i - 1] + (dx[i] + 2 * dx[i - 1]) / slope[i]);
  }
  tangent[n - 1] = slope[n - 2];

  let d = `M${points[0][0]},${points[0][1]}`;
  for (let i = 0; i < n - 1; i++) {
    const [x0, y0] = points[i];
    const [x1, y1] = points[i + 1];
    const third = dx[i] / 3;
    d += `C${x0 + third},${y0 + tangent[i] * third},${x1 - third},${y1 - tangent[i + 1] * third},${x1},${y1}`;
  }
  return d;
}

/** A bar from the baseline up, its top corners rounded by `radius`. */
export function barPath(x: number, y: number, width: number, height: number, radius: number): string {
  if (height <= 0 || width <= 0) return '';
  const r = Math.min(radius, width / 2, height);
  return `M${x},${y + height}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${y + height}Z`;
}
