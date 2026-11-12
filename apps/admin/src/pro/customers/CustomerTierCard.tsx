import { useState } from 'react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { toastApiError } from '@/lib/api/api';
import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints, shortPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import type { TiersCalendarPeriod } from '@/lib/schema/tier.schema';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import { DELETED_ACCOUNT_ACTION_REASON } from '@/pages/customers/drawer/customer-drawer.copy';
import { CustomerDrawerStat } from '@/pages/customers/drawer/CustomerDrawerStat';
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
      <div className="rounded-md border">
        <div className="grid grid-cols-2 gap-4 p-4">
          <CustomerDrawerStat
            label={__('Tier', 'yayloyalty')}
            size="lg"
            value={tier ? <TierBadge name={tier.name} color={tier.color} /> : __('No tier', 'yayloyalty')}
            subLine={tier && achievedAt ? tierSince(achievedAt, showsHold ? heldUntil : null) : undefined}
          />
          <div className="flex min-w-0 flex-col gap-2">
            <CustomerDrawerStat
              label={__('Next tier', 'yayloyalty')}
              value={
                next
                  ? sprintf(
                      /* translators: 1: points still to earn, such as "500 pts"; 2: the next tier's name. */
                      __('%1$s to %2$s', 'yayloyalty'),
                      shortPoints(Math.max(0, next.threshold - progress)),
                      next.name,
                    )
                  : __('Top tier', 'yayloyalty')
              }
            />
            {next && (
              <div className="flex flex-col gap-1">
                <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                  <div
                    className="bg-primary h-full rounded-full"
                    style={{ width: `${progressPercent(progress, next.threshold)}%` }}
                  />
                </div>
                <span className="text-muted-foreground text-xs tabular-nums">
                  {progressCaption(progress, next.threshold, mode === 'calendar' ? period : null, windowMonths)}
                </span>
              </div>
            )}
          </div>
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

/** The bar's caption: this period's total in Calendar, the window's in Rolling, else the plain total. */
function progressCaption(
  progress: number,
  threshold: number,
  period: TiersCalendarPeriod | null,
  windowMonths: number | null,
): string {
  const earned = formatPoints(progress);
  const needed = formatPoints(threshold);

  if (period === 'month') {
    /* translators: 1: points earned this period, 2: points the next tier needs. */
    return sprintf(__('%1$s / %2$s this month', 'yayloyalty'), earned, needed);
  }

  if (period === 'quarter') {
    /* translators: 1: points earned this period, 2: points the next tier needs. */
    return sprintf(__('%1$s / %2$s this quarter', 'yayloyalty'), earned, needed);
  }

  if (period === 'year') {
    /* translators: 1: points earned this period, 2: points the next tier needs. */
    return sprintf(__('%1$s / %2$s this year', 'yayloyalty'), earned, needed);
  }

  if (windowMonths !== null) {
    return sprintf(
      /* translators: 1: points earned in the window, 2: points the next tier needs, 3: the window's length in months. */
      __('%1$s / %2$s in the last %3$s months', 'yayloyalty'),
      earned,
      needed,
      String(windowMonths),
    );
  }

  /* translators: 1: points earned, 2: points the next tier needs. */
  return sprintf(__('%1$s / %2$s', 'yayloyalty'), earned, needed);
}

/** The TIER sub-line; a running Rolling or Calendar hold adds its end. */
function tierSince(achievedAt: string, heldUntil: string | null): string {
  return heldUntil
    ? sprintf(
        /* translators: 1: the date the customer reached the tier, 2: the date their tier hold ends. */
        __('Since %1$s · held until %2$s', 'yayloyalty'),
        formatStoredDate(achievedAt),
        formatStoredDate(heldUntil),
      )
    : sprintf(
        /* translators: %s: the date the customer reached the tier. */
        __('Since %s', 'yayloyalty'),
        formatStoredDate(achievedAt),
      );
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
