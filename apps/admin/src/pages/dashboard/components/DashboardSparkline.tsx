import { cn } from '@/lib/utils';

const WIDTH = 80;
const HEIGHT = 28;

/** A tiny trend line in the current text colour; an all-zero series draws flat along the bottom. */
export function DashboardSparkline({ values, className }: { values: number[]; className?: string }) {
  if (values.length < 2) return null;

  const max = Math.max(...values, 1);
  const step = WIDTH / (values.length - 1);
  const points = values
    .map((v, i) => `${(i * step).toFixed(1)},${(HEIGHT - 1 - (Math.max(v, 0) / max) * (HEIGHT - 2)).toFixed(1)}`)
    .join(' ');

  return (
    <svg
      aria-hidden
      viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
      preserveAspectRatio="none"
      className={cn('h-7 w-20 shrink-0', className)}
    >
      <polyline points={points} fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinejoin="round" />
    </svg>
  );
}
