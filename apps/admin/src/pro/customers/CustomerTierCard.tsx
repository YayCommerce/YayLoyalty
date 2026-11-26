import { useState } from 'react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { toastApiError } from '@/lib/api/api';
import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import type { TiersCalendarPeriod } from '@/lib/schema/tier.schema';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import TierCrownIcon from '@/components/icons/TierCrownIcon';
import { DELETED_ACCOUNT_ACTION_REASON } from '@/pages/customers/drawer/customer-drawer.copy';
import { TierBadge } from '../tiers/TierBadge';
import { useCustomerTierQuery, useRemoveCustomerTierMutation, useTiersEnabled } from '../tiers/tiers.queries';

/**
 * The drawer's tier card: the customer's tier, the way to the next one, and any manual floor, which staff can
 * remove here but no longer assign. Renders nothing while tiers are off.
 */
export function CustomerTierCard({ customer }: { customer: Customer }) {
  const enabled = useTiersEnabled();
  const query = useCustomerTierQuery(customer.user_id, enabled);
  const [removeOpen, setRemoveOpen] = useState(false);
  const isDeleted = !!customer.deleted_at;

  if (!enabled) {
    return null;
  }

  if (query.isError) {
    return <ErrorState message={__('Could not load the tier.', 'yayloyalty')} onRetry={() => query.refetch()} />;
  }

  if (query.isPending) {
    return <Skeleton className="h-36 w-full rounded-md" />;
  }

  const {
    tier,
    next,
    progress_value: progress,
    achieved_at: achievedAt,
    manual,
    mode,
    window_months: windowMonths,
    period,
    held_until: heldUntil,
  } = query.data;
  const showsHold = mode === 'rolling' || mode === 'calendar';

  return (
    <div className="flex flex-col gap-2">
      <div className="rounded-lg border">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
          <div className="flex min-w-0 items-center gap-3">
            <span
              className="bg-muted text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-md"
              style={
                tier
                  ? { backgroundColor: `color-mix(in srgb, ${tier.color} 15%, transparent)`, color: tier.color }
                  : undefined
              }
              aria-hidden
            >
              <TierCrownIcon className="size-5" />
            </span>
            <div className="flex min-w-0 flex-col gap-0.5">
              <span className="text-base font-semibold">
                {tier
                  ? sprintf(
                      /* translators: %s: the tier's name, such as Silver. */
                      __('%s tier', 'yayloyalty'),
                      tier.name,
                    )
                  : __('No tier', 'yayloyalty')}
              </span>
              {tier && achievedAt && (
                <span className="text-muted-foreground text-xs">
                  {sprintf(
                    /* translators: %s: the date the customer reached the tier. */
                    __('Since %s', 'yayloyalty'),
                    formatStoredDate(achievedAt),
                  )}
                </span>
              )}
            </div>
          </div>
          {tier && showsHold && heldUntil && (
            <Badge variant="secondary">
              {sprintf(
                /* translators: %s: the date the customer's tier hold ends. */
                __('Held until %s', 'yayloyalty'),
                formatStoredDate(heldUntil),
              )}
            </Badge>
          )}
        </div>

        <div className="flex flex-col gap-2 p-4">
          <div className="flex items-end justify-between gap-3">
            <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
              {tierPointsLabel(mode === 'calendar' ? period : null, windowMonths)}
            </span>
            <span className="text-2xl font-semibold tabular-nums">
              {sprintf(
                /* translators: %s: the customer's tier points. */
                __('%s pts', 'yayloyalty'),
                formatPoints(progress),
              )}
            </span>
          </div>
          {next ? (
            <>
              <div className="bg-muted h-2 overflow-hidden rounded-full">
                <div
                  className="bg-primary h-full rounded-full"
                  style={{ width: `${progressPercent(progress, next.threshold)}%` }}
                />
              </div>
              <div className="flex items-center justify-between gap-3 text-xs">
                <span className="font-medium">
                  {sprintf(
                    /* translators: %s: the next tier's name. */
                    __('Progress to %s', 'yayloyalty'),
                    next.name,
                  )}
                </span>
                <span className="text-muted-foreground tabular-nums">
                  {sprintf(
                    /* translators: %s: points still to earn. */
                    __('%s pts to go', 'yayloyalty'),
                    formatPoints(Math.max(0, next.threshold - progress)),
                  )}
                </span>
              </div>
            </>
          ) : (
            <span className="text-muted-foreground text-xs">{__('Top tier reached.', 'yayloyalty')}</span>
          )}
        </div>

        {manual && (
          <div className="flex flex-col gap-2 border-t px-4 py-3">
            <span className="text-muted-foreground flex flex-wrap items-center gap-1 text-xs">
              {__('Manual tier:', 'yayloyalty')}
              <TierBadge name={manual.name} color={manual.color} />
              {manual.until
                ? sprintf(
                    /* translators: %s: the date the manual tier ends. */
                    __('· until %s', 'yayloyalty'),
                    formatStoredDate(manual.until),
                  )
                : __('· no end', 'yayloyalty')}
            </span>
            <div className="flex flex-wrap gap-2">
              <Can cap={CAP.MANAGE_TIERS} disable>
                <Button variant="outline" size="sm" disabled={isDeleted} onClick={() => setRemoveOpen(true)}>
                  {__('Remove manual tier', 'yayloyalty')}
                </Button>
              </Can>
            </div>
          </div>
        )}
      </div>
      {manual && isDeleted && <p className="text-muted-foreground text-xs">{DELETED_ACCOUNT_ACTION_REASON()}</p>}

      <CustomerTierRemoveDialog customer={customer} open={removeOpen} onClose={() => setRemoveOpen(false)} />
    </div>
  );
}

/** The TIER POINTS label: names Calendar's period or Rolling's window, since the figure counts only those points. */
function tierPointsLabel(period: TiersCalendarPeriod | null, windowMonths: number | null): string {
  if (period === 'month') {
    return __('Tier points this month', 'yayloyalty');
  }

  if (period === 'quarter') {
    return __('Tier points this quarter', 'yayloyalty');
  }

  if (period === 'year') {
    return __('Tier points this year', 'yayloyalty');
  }

  if (windowMonths !== null) {
    return sprintf(
      /* translators: %s: the rolling window's length in months. */
      __('Tier points, last %s months', 'yayloyalty'),
      String(windowMonths),
    );
  }

  return __('Tier points', 'yayloyalty');
}

/** The bar's fill, 0–100. A next tier at 0 points is already reached. */
function progressPercent(progress: number, threshold: number): number {
  return threshold <= 0 ? 100 : Math.min(100, Math.max(0, (progress / threshold) * 100));
}

/** Confirms removing the manual floor. */
function CustomerTierRemoveDialog({
  customer,
  open,
  onClose,
}: {
  customer: Customer;
  open: boolean;
  onClose: () => void;
}) {
  const remove = useRemoveCustomerTierMutation(customer.user_id);

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => !next && !remove.isPending && onClose()}
      title={__('Remove manual tier?', 'yayloyalty')}
      description={sprintf(
        /* translators: %s: the customer's name. */
        __('%s goes back to the tier their earned points qualify for, which may be lower.', 'yayloyalty'),
        customer.name,
      )}
      confirmLabel={__('Remove', 'yayloyalty')}
      cancelLabel={__('Cancel', 'yayloyalty')}
      isLoading={remove.isPending}
      onConfirm={() =>
        remove.mutate(undefined, {
          onSuccess: () => {
            toast.success(__('Manual tier removed.', 'yayloyalty'));
            onClose();
          },
          onError: (error) => toastApiError(error, __('Could not remove the manual tier.', 'yayloyalty')),
        })
      }
    />
  );
}
