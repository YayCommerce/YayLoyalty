import type { MouseEvent } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { parseWPDate, parseWPRelativeDate } from '@/lib/helpers/format.helper';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { cn } from '@/lib/utils';
import { Checkbox } from '@/components/ui/checkbox';
import { PointsValue } from '@/components/ui/custom/points-value';
import { TableCell, TableRow } from '@/components/ui/table';
import { BalanceCell, customerDrawerPath, CustomerNameCell } from './CustomersTableCells';

/**
 * One customer row. A negative balance gets a left-accent tint, never red.
 * A click anywhere on the row opens the drawer, except on the checkbox or the name link, which handle their own clicks.
 */
export function CustomerTableRow({
  customer,
  showSelection,
  selected,
  onToggle,
}: {
  customer: CustomerListRow;
  showSelection: boolean;
  selected: boolean;
  onToggle: (userId: number) => void;
}) {
  const negative = customer.balance < 0;
  const [searchParams] = useSearchParams();
  const search = searchParams.toString();
  const navigate = useNavigate();

  const openDrawer = (event: MouseEvent<HTMLTableRowElement>) => {
    if ((event.target as HTMLElement).closest('a, button, input, label, [role="checkbox"]')) {
      return;
    }
    navigate(customerDrawerPath(customer, search), { state: { row: customer } });
  };

  return (
    <TableRow
      data-state={selected ? 'selected' : undefined}
      onClick={openDrawer}
      className={cn('cursor-pointer', negative && 'bg-muted/40 border-l-muted-foreground/40 border-l-[3px]')}
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
        <CustomerNameCell customer={customer} search={search} />
      </TableCell>
      <TableCell className="text-right">
        <BalanceCell value={customer.balance} />
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
    </TableRow>
  );
}
