import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { CampaignPerformance, CampaignPerformanceTrigger } from '@/lib/schema/campaign-performance.schema';
import { PointsValue } from '@/components/ui/custom/points-value';
import { StatTile } from '@/components/ui/custom/stat-tile';

type Totals = CampaignPerformance['totals'];

/** Purchase gets 3 tiles across `lg`; signup/review get 2 — same breakpoint both states use. */
function gridClass(trigger: CampaignPerformanceTrigger) {
  return trigger === 'purchase' ? 'grid grid-cols-2 gap-3 lg:grid-cols-3' : 'grid grid-cols-2 gap-3';
}

function ordersSubline(totals: Totals, hasData: boolean): string {
  if (!hasData) {
    return __('none yet', 'yayloyalty');
  }
  // Unreachable today: PHP always sets orders_count_all_time for purchase, the only trigger this tile
  // renders. The type is nullable for the other triggers, so this guards against that drifting.
  if (totals.orders_count_all_time === null) {
    return '';
  }
  return sprintf(
    /* translators: %s: total number of completed orders the store has ever had. */
    __('of %s completed orders', 'yayloyalty'),
    formatPoints(totals.orders_count_all_time),
  );
}

function averagePerOrderSubline(totals: Totals): string {
  if (totals.avg_points_per_order === null) {
    return __('needs at least one order', 'yayloyalty');
  }
  // avg_order_subtotal is not computed yet: say nothing rather than "$0.00 average order".
  if (totals.avg_order_subtotal === null) {
    return __('points per order', 'yayloyalty');
  }
  return sprintf(
    /* translators: %s: the average order subtotal, formatted as money. */
    __('points · %s average order', 'yayloyalty'),
    parseWPCurrency(totals.avg_order_subtotal),
  );
}

/**
 * Per-trigger tile set. `hasData`, not the raw counts, picks the empty-state sublines; a real 0
 * still renders as a count. A null value shows '—', as DashboardStatsGrid does.
 */
export function CampaignPerformanceTiles({
  trigger,
  totals,
  hasData,
}: {
  trigger: CampaignPerformanceTrigger;
  totals: Totals;
  hasData: boolean;
}) {
  return (
    <div className={gridClass(trigger)}>
      <StatTile
        label={__('Points issued', 'yayloyalty')}
        value={<PointsValue value={totals.points_issued} />}
        subline={__('by this campaign', 'yayloyalty')}
      />
      {trigger === 'purchase' && (
        <StatTile
          label={__('Orders affected', 'yayloyalty')}
          value={formatPoints(totals.orders_affected ?? 0)}
          subline={ordersSubline(totals, hasData)}
        />
      )}
      {trigger !== 'purchase' && (
        <StatTile
          label={__('Customers earned', 'yayloyalty')}
          value={formatPoints(totals.customers_earned_count ?? 0)}
          subline={hasData ? __('earned points from this campaign', 'yayloyalty') : __('none yet', 'yayloyalty')}
        />
      )}
      {trigger === 'purchase' && (
        <StatTile
          label={__('Average per order', 'yayloyalty')}
          value={totals.avg_points_per_order === null ? '—' : formatPoints(totals.avg_points_per_order)}
          subline={averagePerOrderSubline(totals)}
        />
      )}
    </div>
  );
}
