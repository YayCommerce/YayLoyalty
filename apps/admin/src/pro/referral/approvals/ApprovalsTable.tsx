import { Link } from 'react-router';

import { formatWPDate } from '@/lib/helpers/format.helper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { REFERRALS_COPY } from '../referrals.copy';
import type { ReferralListItem } from '../referrals.schema';
import { toReferralRow } from '../referrals/referral-row';
import { APPROVALS_COPY } from './approvals.copy';

/** Held referrals, oldest hold end first. With `canAct`, each row has a checkbox and its Approve and Reject buttons. */
export function ApprovalsTable({
  items,
  isLoading,
  canAct,
  selected,
  busy,
  onToggle,
  onToggleAll,
  onApprove,
  onReject,
}: {
  items: ReferralListItem[];
  isLoading: boolean;
  canAct: boolean;
  selected: Set<number>;
  busy: Set<number>;
  onToggle: (id: number) => void;
  onToggleAll: () => void;
  onApprove: (item: ReferralListItem) => void;
  onReject: (item: ReferralListItem) => void;
}) {
  const columns = canAct ? 8 : 6;
  const picked = items.filter((item) => selected.has(item.id)).length;

  return (
    <Table>
      <TableHeader>
        <TableRow>
          {canAct && (
            <TableHead>
              <Checkbox
                checked={picked > 0 && picked === items.length ? true : picked > 0 ? 'indeterminate' : false}
                onCheckedChange={onToggleAll}
                disabled={items.length === 0}
                aria-label={APPROVALS_COPY.selectAll()}
              />
            </TableHead>
          )}
          <TableHead>{REFERRALS_COPY.columnAdvocate()}</TableHead>
          <TableHead>{REFERRALS_COPY.columnFriend()}</TableHead>
          <TableHead>{APPROVALS_COPY.columnWhy()}</TableHead>
          <TableHead>{REFERRALS_COPY.columnOrder()}</TableHead>
          <TableHead className="text-right">{REFERRALS_COPY.columnValue()}</TableHead>
          <TableHead>{APPROVALS_COPY.columnHeldSince()}</TableHead>
          {canAct && <TableHead />}
        </TableRow>
      </TableHeader>
      <TableBody>
        {isLoading ? (
          Array.from({ length: 5 }).map((_, index) => (
            <TableRow key={index}>
              {Array.from({ length: columns }).map((_cell, cell) => (
                <TableCell key={cell}>
                  <Skeleton className="h-3 w-16" />
                </TableCell>
              ))}
            </TableRow>
          ))
        ) : items.length === 0 ? (
          <TableRow>
            <TableCell colSpan={columns} className="text-muted-foreground py-10 text-center text-sm">
              {APPROVALS_COPY.empty()}
            </TableCell>
          </TableRow>
        ) : (
          items.map((item) => {
            const row = toReferralRow(item);
            const isBusy = busy.has(item.id);

            return (
              <TableRow key={item.id}>
                {canAct && (
                  <TableCell>
                    <Checkbox
                      checked={selected.has(item.id)}
                      onCheckedChange={() => onToggle(item.id)}
                      aria-label={APPROVALS_COPY.selectRow()}
                    />
                  </TableCell>
                )}
                <TableCell className="max-w-48">
                  <Link to={`/referral/approvals/${item.advocate.id}`} className="block truncate font-medium hover:underline">
                    {item.advocate.name}
                  </Link>
                </TableCell>
                <TableCell>
                  <YayLoyaltyToolTip trigger={<span className="cursor-default">{row.friend}</span>} content={item.friend.email} />
                </TableCell>
                <TableCell>
                  <span className="flex flex-col gap-0.5">
                    <Badge variant="warning-soft">{APPROVALS_COPY.holdEnding()}</Badge>
                    {item.hold_until && (
                      <span className="text-muted-foreground text-xs">
                        {APPROVALS_COPY.holdEnds(formatWPDate(item.hold_until))}
                      </span>
                    )}
                  </span>
                </TableCell>
                <TableCell>
                  {row.orderUrl ? (
                    <a href={row.orderUrl} className="hover:underline">
                      {row.order}
                    </a>
                  ) : (
                    row.order
                  )}
                </TableCell>
                <TableCell className="text-right tabular-nums">{row.value}</TableCell>
                <TableCell className="text-muted-foreground whitespace-nowrap">{formatWPDate(item.updated_at)}</TableCell>
                {canAct && (
                  <TableCell className="text-right whitespace-nowrap">
                    <Button variant="ghost" size="sm" disabled={isBusy} onClick={() => onReject(item)}>
                      {APPROVALS_COPY.reject()}
                    </Button>
                    <Button size="sm" disabled={isBusy} onClick={() => onApprove(item)}>
                      {APPROVALS_COPY.approve()}
                    </Button>
                  </TableCell>
                )}
              </TableRow>
            );
          })
        )}
      </TableBody>
    </Table>
  );
}
