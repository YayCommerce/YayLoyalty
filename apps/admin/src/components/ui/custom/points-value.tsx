import { formatCompactPoints, formatPoints, isCompactedPoints } from '@/lib/helpers/points.helper';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';

/**
 * Displays a points/balance figure, compacted ("150k+", "2M+", "1B+") at or
 * above 100,000 — see points.helper.ts. Compacted values get a hover
 * tooltip with the exact number; anything already shown in full doesn't
 * (nothing to reveal).
 *
 * Cross-page by design: any table cell, stat tile or ledger row that shows a
 * points number should use this instead of calling formatPoints() directly,
 * so the compact-plus-hover behaviour stays the same everywhere.
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
