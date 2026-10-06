import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { Badge } from '@/components/ui/badge';
import { sampleTierProgress } from '../customers-page.tier-sample-data';
import { tierProgress, TOP_TIER_REACHED_LABEL } from './customer-drawer.copy';

/**
 * The sample tier and progress from the list's tier stand-in, until a tiers table exists.
 * Hidden for a negative balance or a deleted account. The badge is `outline`: `dimmed-outline` would blend into the tint.
 */
export function CustomerDrawerTierCard({ customer }: { customer: Customer }) {
  const progress = sampleTierProgress(customer.balance);

  if (!progress || customer.deleted_at) return null;

  return (
    <div className="bg-muted/50 flex items-center gap-2.5 rounded-md border px-3.5 py-2.5">
      <Badge variant="outline" className="tracking-wide uppercase">
        {progress.tier}
      </Badge>
      <span className="text-muted-foreground text-xs">
        {progress.next && progress.pointsToNext !== null
          ? tierProgress(formatPoints(progress.pointsToNext), progress.next)
          : TOP_TIER_REACHED_LABEL()}
      </span>
    </div>
  );
}
