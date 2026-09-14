import { __ } from '@wordpress/i18n';

import { parseWPDate, parseWPRelativeDate } from '@/lib/helpers/format.helper';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { PointsValue } from '@/components/ui/custom/points-value';
import { TableCell, TableRow } from '@/components/ui/table';
import { BalanceCell, CustomerNameCell } from './CustomersTableCells';

/**
 * One customer row. Negative balance gets a subtle left-accent tint (never
 * red — see BalanceCell) and shows "—" for Expires instead of a stale date.
 *
 * Tier and Referrals (Pro) always render "—": no `tiers` table and no
 * referral-count column exist yet, so `CustomerListRow` carries neither field
 * — this isn't a loading/error state, it's the real (empty) answer. See
 * `CustomersToolbar.tsx` for the matching Tier filter caveat.
 */
export function CustomerTableRow({
  customer,
  showProColumns,
  showSelection,
  selected,
  onToggle,
  stale,
}: {
  customer: CustomerListRow;
  showProColumns: boolean;
  /** Off during the rebuild toggle even in Pro — no picking rows while balances are frozen. */
  showSelection: boolean;
  selected: boolean;
  onToggle: (userId: number) => void;
  stale?: boolean;
}) {
  const negative = customer.balance < 0;

  return (
    <TableRow
      data-state={selected ? 'selected' : undefined}
      className={negative ? 'bg-muted/40 border-l-muted-foreground/40 border-l-[3px]' : undefined}
    >
      {showSelection && (
        <TableCell>
          <Checkbox
            checked={selected}
            onCheckedChange={() => onToggle(customer.user_id)}
            aria-label={__('Select row', 'yayloyalty')}
          />
        </TableCell>
      )}
      <TableCell>
        <CustomerNameCell name={customer.name} email={customer.email} />
      </TableCell>
      {showProColumns && (
        <TableCell>
          <Badge variant="dimmed-outline">—</Badge>
        </TableCell>
      )}
      <TableCell className="text-right">
        <BalanceCell value={customer.balance} stale={stale} />
      </TableCell>
      <TableCell className="text-muted-foreground text-right tabular-nums">
        <PointsValue value={customer.pending_points} />
      </TableCell>
      <TableCell className="text-muted-foreground text-right tabular-nums">
        <PointsValue value={customer.lifetime_earned} />
      </TableCell>
      <TableCell className="text-muted-foreground text-right">
        {customer.last_activity_at ? parseWPRelativeDate(customer.last_activity_at) : '—'}
      </TableCell>
      <TableCell className="text-muted-foreground text-right">
        {!negative && customer.expires_at ? parseWPDate(customer.expires_at) : '—'}
      </TableCell>
      {showProColumns && <TableCell className="text-muted-foreground text-right tabular-nums">—</TableCell>}
    </TableRow>
  );
}
