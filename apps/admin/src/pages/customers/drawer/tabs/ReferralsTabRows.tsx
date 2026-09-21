import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReferralRow } from '../customer-drawer.referral-rows';
import {
  COLUMN_FIRST_ORDER,
  COLUMN_REFERRAL_STATUS,
  COLUMN_REFERRED,
  COLUMN_SIGNED_UP,
  COLUMN_TO_FRIEND,
  COLUMN_TO_REFERRER,
  EMPTY_REFERRALS_BODY,
  EMPTY_REFERRALS_TITLE,
} from '../customer-drawer.referrals.copy';

const COLUMN_COUNT = 6;
const SKELETON_ROW_COUNT = 10;

/**
 * 3f's six columns — Referred / Signed up / First order / To referrer / To
 * friend / Status. Same `flex-wrap` + `order-*` below-`sm` stacking
 * `RedemptionsTabRows.tsx` uses — copied, not reinvented (phase 06 note).
 */
export function ReferralsTabRows({ rows, isLoading }: { rows: ReferralRow[]; isLoading: boolean }) {
  return (
    // `sm:min-w-max` + `block`/`sm:table` on Table/TableBody, header hidden
    // below `sm` — see LedgerTabRows.tsx's docblock: a flexed `<tr>` inside a
    // real `<table>`/`<tbody>` doesn't stretch to the table's width in
    // Chromium (verified live), so those ancestors must leave table layout
    // too, not just `tr`/`td`.
    <Table className="block sm:table sm:min-w-max">
      <TableHeader className="hidden sm:table-header-group">
        <TableRow>
          <TableHead>{COLUMN_REFERRED()}</TableHead>
          <TableHead>{COLUMN_SIGNED_UP()}</TableHead>
          <TableHead>{COLUMN_FIRST_ORDER()}</TableHead>
          <TableHead className="text-right">{COLUMN_TO_REFERRER()}</TableHead>
          <TableHead className="text-right">{COLUMN_TO_FRIEND()}</TableHead>
          <TableHead className="text-right">{COLUMN_REFERRAL_STATUS()}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody className="block sm:table-row-group">
        {isLoading ? (
          Array.from({ length: SKELETON_ROW_COUNT }, (_, index) => (
            <TableRow key={index}>
              <TableCell colSpan={COLUMN_COUNT}>
                <Skeleton className="h-5 w-full" />
              </TableCell>
            </TableRow>
          ))
        ) : rows.length === 0 ? (
          <TableRow>
            <TableCell colSpan={COLUMN_COUNT} className="h-auto py-12 text-center">
              <p className="text-sm font-medium">{EMPTY_REFERRALS_TITLE()}</p>
              <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">{EMPTY_REFERRALS_BODY()}</p>
            </TableCell>
          </TableRow>
        ) : (
          rows.map((row, index) => (
            <TableRow key={index} className="flex flex-wrap sm:table-row">
              <TableCell className="order-1 w-1/2 whitespace-normal sm:order-0 sm:table-cell sm:w-auto sm:whitespace-nowrap">
                <div className="font-medium">{row.referredName}</div>
                <div className="text-muted-foreground text-xs">{row.referredEmail}</div>
              </TableCell>
              <TableCell className="order-2 w-1/2 whitespace-normal sm:order-0 sm:table-cell sm:w-auto sm:whitespace-nowrap">
                {row.signedUpAt}
              </TableCell>
              <TableCell className="order-3 w-1/2 whitespace-normal sm:order-0 sm:table-cell sm:w-auto sm:whitespace-nowrap">
                {row.firstOrder}
              </TableCell>
              <TableCell className="order-4 w-1/2 text-right whitespace-normal tabular-nums sm:order-0 sm:table-cell sm:w-auto sm:whitespace-nowrap">
                {row.referrerPoints}
              </TableCell>
              <TableCell className="text-muted-foreground order-5 w-1/2 text-right whitespace-normal sm:order-0 sm:table-cell sm:w-auto sm:whitespace-nowrap">
                {row.friendCoupon}
              </TableCell>
              <TableCell className="order-6 w-1/2 text-right whitespace-normal sm:order-0 sm:table-cell sm:w-auto sm:whitespace-nowrap">
                <Badge variant="outline">{row.status}</Badge>
                {row.statusNote && <div className="text-muted-foreground mt-1 text-xs">{row.statusNote}</div>}
              </TableCell>
            </TableRow>
          ))
        )}
      </TableBody>
    </Table>
  );
}
