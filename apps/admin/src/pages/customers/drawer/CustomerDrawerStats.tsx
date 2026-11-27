import type { ComponentType, ReactNode } from 'react';
import { ClockIcon, GiftIcon, TrendingUpIcon, TriangleAlert, WalletIcon } from 'lucide-react';

import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import {
  BALANCE_AT_DELETION_LABEL,
  BALANCE_AT_DELETION_SUBLINE,
  EXPIRY_STOPPED_SUBLINE,
  PENDING_SUBLINE,
  STAT_BALANCE,
  STAT_LIFETIME_EARNED,
  STAT_LIFETIME_REDEEMED,
  STAT_NEXT_EXPIRY,
  STAT_PENDING,
  STATS_TITLE,
} from './customer-drawer.copy';

/** The "Loyalty overview" card, all derived from `customer`: next expiry in the header, then four figures. */
export function CustomerDrawerStats({ customer }: { customer: Customer }) {
  const isDeleted = !!customer.deleted_at;
  const negative = customer.balance < 0;
  const expiresAt = isDeleted ? null : customer.expires_at;

  return (
    <div className="rounded-lg border">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
        <h3 className="m-0 text-sm font-semibold">{STATS_TITLE()}</h3>
        <span className="text-muted-foreground text-xs">
          <span className="text-foreground font-medium">{STAT_NEXT_EXPIRY()}</span>{' '}
          {isDeleted ? EXPIRY_STOPPED_SUBLINE() : expiresAt ? formatStoredDate(expiresAt) : '—'}
        </span>
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-4 sm:divide-x">
        <OverviewCell
          icon={WalletIcon}
          label={isDeleted ? BALANCE_AT_DELETION_LABEL() : STAT_BALANCE()}
          value={
            negative ? (
              <span className="flex items-center gap-1.5">
                <TriangleAlert className="text-muted-foreground size-4" />
                {formatPoints(customer.balance)}
              </span>
            ) : (
              formatPoints(customer.balance)
            )
          }
          subLine={isDeleted ? BALANCE_AT_DELETION_SUBLINE() : undefined}
        />
        <OverviewCell
          icon={ClockIcon}
          label={STAT_PENDING()}
          value={formatPoints(customer.pending_points)}
          title={PENDING_SUBLINE()}
        />
        <OverviewCell
          icon={TrendingUpIcon}
          label={STAT_LIFETIME_EARNED()}
          value={formatPoints(customer.lifetime_earned)}
        />
        <OverviewCell
          icon={GiftIcon}
          label={STAT_LIFETIME_REDEEMED()}
          value={formatPoints(customer.lifetime_redeemed)}
        />
      </div>
    </div>
  );
}

function OverviewCell({
  icon: Icon,
  label,
  value,
  subLine,
  title,
}: {
  icon: ComponentType<{ className?: string }>;
  label: string;
  value: ReactNode;
  subLine?: string;
  title?: string;
}) {
  return (
    <div className="flex min-w-0 flex-col gap-1 p-4" title={title}>
      <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
        <Icon className="size-3.5" />
        {label}
      </span>
      <span className="text-2xl font-semibold tabular-nums">{value}</span>
      {subLine && <span className="text-muted-foreground text-xs">{subLine}</span>}
    </div>
  );
}
