import type { ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';
import { useSearchParams } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate, parseWPRelativeDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PointsValue } from '@/components/ui/custom/points-value';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { sampleTierForBalance } from '../customers-page.tier-sample-data';
import { BalanceCell, CustomerNameCell } from './CustomersTableCells';

/**
 * The mobile customer card. Identity and balance always show, Pending and Last activity share one line,
 * and the other columns sit in a "Details" popover, so no column is silently dropped.
 * No selection checkbox: bulk actions are desktop-only.
 */
export function CustomerCardRow({ customer, showProColumns }: { customer: CustomerListRow; showProColumns: boolean }) {
  const negative = customer.balance < 0;
  const sampleTier = sampleTierForBalance(customer.balance);
  const [searchParams] = useSearchParams();
  const search = searchParams.toString();

  return (
    <div
      className={cn('flex flex-col gap-2 p-3.5', negative && 'bg-muted/40 border-l-muted-foreground/40 border-l-[3px]')}
    >
      <div className="flex items-start justify-between gap-3">
        <CustomerNameCell customer={customer} search={search} />
        <div className="shrink-0">
          <BalanceCell value={customer.balance} />
        </div>
      </div>
      <div className="flex items-center justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2 text-xs">
          {showProColumns && <Badge variant="dimmed-outline">{sampleTier ?? '—'}</Badge>}
          <span className="text-muted-foreground truncate">{summaryLine(customer, negative)}</span>
        </div>
        <CardDetailsPopover customer={customer} negative={negative} showProColumns={showProColumns} />
      </div>
    </div>
  );
}

/** Pending points and last activity, shown in the card body rather than in Details. */
function summaryLine(customer: CustomerListRow, negative: boolean): string {
  if (negative) {
    return __('negative balance', 'yayloyalty');
  }

  const activity = customer.last_activity_at
    ? sprintf(
        /* translators: %s: how long ago the customer was last active, such as "2 days ago". */
        __('active %s', 'yayloyalty'),
        parseWPRelativeDate(customer.last_activity_at),
      )
    : null;

  if (customer.pending_points > 0) {
    const pending = sprintf(
      /* translators: %s: pending points, formatted. */
      __('%s pending', 'yayloyalty'),
      formatPoints(customer.pending_points),
    );

    return activity ? `${pending} · ${activity}` : pending;
  }

  return activity ?? '—';
}

/** The columns the card does not show inline: Lifetime earned, Expires and Referrals (Pro). */
function CardDetailsPopover({
  customer,
  negative,
  showProColumns,
}: {
  customer: CustomerListRow;
  negative: boolean;
  showProColumns: boolean;
}) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="sm" className="text-muted-foreground h-auto gap-0.5 px-1.5 py-1 text-xs">
          {__('Details', 'yayloyalty')}
          <ChevronDown className="size-3" />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="flex w-56 flex-col gap-2 text-sm">
        <DetailRow label={__('Lifetime earned', 'yayloyalty')}>
          <PointsValue value={customer.lifetime_earned} />
        </DetailRow>
        <DetailRow label={__('Expires', 'yayloyalty')}>
          {!negative && customer.expires_at ? parseWPDate(customer.expires_at) : '—'}
        </DetailRow>
        {showProColumns && <DetailRow label={__('Referrals', 'yayloyalty')}>—</DetailRow>}
      </PopoverContent>
    </Popover>
  );
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="tabular-nums">{children}</dd>
    </div>
  );
}
