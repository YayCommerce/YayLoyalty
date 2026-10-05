import { formatCompactPoints, formatPoints, isCompactedPoints } from '@/lib/helpers/points.helper';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';

/**
 * A points figure, compacted at or above 100,000 ("150k+", see points.helper.ts) with the exact
 * number in a tooltip. Use it wherever a points number shows, so every screen behaves the same.
 */
export function PointsValue({ value, className }: { value: number; className?: string }) {
  const display = formatCompactPoints(value);

  if (!isCompactedPoints(value)) {
    return <span className={className}>{display}</span>;
  }

  return (
    <YayLoyaltyToolTip
      side="top"
      content={formatPoints(value)}
      trigger={
        <span className={className} tabIndex={0}>
          {display}
        </span>
      }
    />
  );
}
