import { Link } from 'react-router';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { REFERRALS_COPY } from '../referrals.copy';
import type { ReferralListItem } from '../referrals.schema';
import { toReferralRow } from './referral-row';

const COLUMN_COUNT = 7;

const SKELETON_ROWS = 10;

/** The Referrals tab's table: a record of every referral, with no row actions. Headers stay through every state. */
export function ReferralsTable({
  items,
  isLoading,
  isFiltered,
}: {
  items: ReferralListItem[];
  isLoading: boolean;
  isFiltered: boolean;
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{REFERRALS_COPY.columnAdvocate()}</TableHead>
          <TableHead>{REFERRALS_COPY.columnFriend()}</TableHead>
          <TableHead>{REFERRALS_COPY.columnStatus()}</TableHead>
          <TableHead>{REFERRALS_COPY.columnOrder()}</TableHead>
          <TableHead className="text-right">{REFERRALS_COPY.columnValue()}</TableHead>
          <TableHead className="text-right">{REFERRALS_COPY.columnReward()}</TableHead>
          <TableHead>{REFERRALS_COPY.columnDate()}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {isLoading ? (
          Array.from({ length: SKELETON_ROWS }).map((_, index) => (
            <TableRow key={index}>
              {Array.from({ length: COLUMN_COUNT }).map((_cell, cell) => (
                <TableCell key={cell}>
                  <Skeleton className={cn('h-3 w-20', (cell === 4 || cell === 5) && 'ml-auto w-12')} />
                </TableCell>
              ))}
            </TableRow>
          ))
        ) : items.length === 0 ? (
          <TableRow>
            <TableCell colSpan={COLUMN_COUNT} className="text-muted-foreground py-10 text-center text-sm">
              {isFiltered ? REFERRALS_COPY.emptyFiltered() : REFERRALS_COPY.empty()}
            </TableCell>
          </TableRow>
        ) : (
          items.map((item) => <ReferralsTableRow key={item.id} item={item} />)
        )}
      </TableBody>
    </Table>
  );
}

function ReferralsTableRow({ item }: { item: ReferralListItem }) {
  const row = toReferralRow(item);

  return (
    <TableRow>
      <TableCell className="max-w-48">
        <Link to={`/referral/referrals/${item.advocate.id}`} className="block truncate font-medium hover:underline">
          {item.advocate.name}
        </Link>
      </TableCell>
      <TableCell>
        <YayLoyaltyToolTip trigger={<span className="cursor-default">{row.friend}</span>} content={item.friend.email} />
      </TableCell>
      <TableCell>
        <span className="flex flex-col gap-0.5">
          <Badge variant={row.variant}>{row.status}</Badge>
          {row.reason && <span className="text-muted-foreground text-xs">{row.reason}</span>}
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
      <TableCell className="text-right tabular-nums">{row.reward}</TableCell>
      <TableCell className="text-muted-foreground whitespace-nowrap">{row.date}</TableCell>
    </TableRow>
  );
}
