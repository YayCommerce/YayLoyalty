import { __ } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { TableCell, TableRow } from '@/components/ui/table';
import type { MockCustomer } from '../customers-page.mock-data';
import { formatPoints } from '../customers-page.mock-data';
import { BalanceCell, CustomerNameCell } from './CustomersTableCells';

/**
 * One customer row. Negative balance gets a subtle left-accent tint (never
 * red — see BalanceCell) and shows "—" for Expires instead of a stale date.
 */
export function CustomerTableRow({
  customer,
  showProColumns,
  showSelection,
  selected,
  onToggle,
  stale,
}: {
  customer: MockCustomer;
  showProColumns: boolean;
  showSelection: boolean;
  selected: boolean;
  onToggle: (userId: number) => void;
  stale?: boolean;
}) {
  const negative = customer.balance < 0;

  return (
    <TableRow
      data-state={selected ? 'selected' : undefined}
      className={negative ? 'bg-muted/40 border-l-[3px] border-l-muted-foreground/40' : undefined}
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
          <Badge variant="dimmed-outline">{customer.tier}</Badge>
        </TableCell>
      )}
      <TableCell className="text-right">
        <BalanceCell value={customer.balance} stale={stale} />
      </TableCell>
      <TableCell className="text-muted-foreground text-right tabular-nums">
        {formatPoints(customer.pending_points)}
      </TableCell>
      <TableCell className="text-muted-foreground text-right tabular-nums">
        {formatPoints(customer.lifetime_earned)}
      </TableCell>
      <TableCell className="text-muted-foreground text-right">{customer.last_activity_label}</TableCell>
      <TableCell className="text-muted-foreground text-right">
        {!negative && customer.expires_at ? parseWPDate(customer.expires_at) : '—'}
      </TableCell>
      {showProColumns && (
        <TableCell className="text-muted-foreground text-right tabular-nums">{customer.referrals}</TableCell>
      )}
    </TableRow>
  );
}
