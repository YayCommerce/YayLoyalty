import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { Badge } from '@/components/ui/badge';
import { sampleTierProgress } from '../customers-page.tier-sample-data';
import { tierProgress, TOP_TIER_REACHED_LABEL } from './customer-drawer.copy';

/**
 * TEMPORARY, UI-CHECK-ONLY — the same sample-data stand-in the Customers
 * list's Tier column uses (`customers-page.tier-sample-data.ts`), not a
 * real feature: no `tiers` table exists yet (`docs/data-model.md`, 1.2).
 *
 * The Wave A plan's locked scope excluded a tier card entirely because
 * there was nothing real to show — added after, at the user's request,
 * reusing the existing stand-in rather than inventing a second one. Delete
 * alongside the rest of that stand-in once VIP Tiers ships a real
 * per-customer tier.
 *
 * Never shown for a negative balance (`sampleTierProgress` returns `null` —
 * no earned status to show, same rule the list's Tier column follows) or a
 * deleted account (no one left to track toward a next tier).
 */
export function CustomerDrawerTierCard({ customer }: { customer: Customer }) {
  const progress = sampleTierProgress(customer.balance);

  if (!progress || customer.deleted_at) return null;

  return (
    <div className="flex items-center gap-2 rounded-lg border p-3">
      <Badge variant="dimmed-outline">{progress.tier}</Badge>
      <span className="text-muted-foreground text-sm">
        {progress.next && progress.pointsToNext !== null
          ? tierProgress(formatPoints(progress.pointsToNext), progress.next)
          : TOP_TIER_REACHED_LABEL()}
      </span>
    </div>
  );
}
