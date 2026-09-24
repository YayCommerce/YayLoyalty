import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { Badge } from '@/components/ui/badge';
import { sampleTierProgress } from '../customers-page.tier-sample-data';
import { tierProgress, TOP_TIER_REACHED_LABEL } from './customer-drawer.copy';

/**
 * TEMPORARY, UI-CHECK-ONLY — the same sample-data stand-in the Customers
 * list's Tier column uses (`customers-page.tier-sample-data.ts`), not a
 * real feature: no `tiers` table exists yet (`docs/data-model/future-tables.md`, 1.2).
 *
 * Reuses the list's stand-in rather than inventing a second one. Delete
 * alongside the rest of that stand-in once VIP Tiers ships a real
 * per-customer tier.
 *
 * Never shown for a negative balance (`sampleTierProgress` returns `null` —
 * no earned status to show, same rule the list's Tier column follows) or a
 * deleted account (no one left to track toward a next tier).
 *
 * Styled after 3d/3c's compact one-line card: a tinted, bordered strip under
 * the identity block, an outlined uppercase tier badge (white on the tint, so
 * `outline` rather than `dimmed-outline`, which would blend into it) and the
 * progress line beside it. 1f draws a taller variant of the same card with a
 * progress bar and the two thresholds under it — not built, because the
 * thresholds it would print come from the sample-data stand-in above, not
 * from a real tiers table.
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
