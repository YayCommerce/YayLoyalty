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
 * 5c's mobile card: identity + balance always visible, Pending/Last activity
 * folded into one contextual second line, and Lifetime earned/Expires/
 * Referrals named inside a "Details ▾" popover — callout 5's rule ("keep the
 * identity, the number the screen is about, and one action; nothing is
 * silently dropped, hidden columns are named") applied to the outer
 * Customers list, not just the drawer's own tables.
 *
 * No selection checkbox here, unlike `CustomerTableRow.tsx` — the mockup
 * draws none, and bulk-select alongside a "Details" popover on one card is
 * a second competing action callout 5 argues against. Bulk actions stay a
 * desktop-only affordance below `sm`.
 */
export function CustomerCardRow({
  customer,
  showProColumns,
  stale,
}: {
  customer: CustomerListRow;
  showProColumns: boolean;
  stale?: boolean;
}) {
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
          <BalanceCell value={customer.balance} stale={stale} />
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

/** Pending + last activity, the two columns 5c moves into the card body instead of Details. */
function summaryLine(customer: CustomerListRow, negative: boolean): string {
  if (negative) {
    return __('negative balance', 'yayloyalty');
  }

  const activity = customer.last_activity_at
    ? sprintf(__('active %s', 'yayloyalty'), parseWPRelativeDate(customer.last_activity_at))
    : null;

  if (customer.pending_points > 0) {
    const pending = sprintf(__('%s pending', 'yayloyalty'), formatPoints(customer.pending_points));

    return activity ? `${pending} · ${activity}` : pending;
  }

  return activity ?? '—';
}

/** The columns 5c names in the expander rather than dropping: Lifetime earned, Expires, Referrals (Pro). */
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
