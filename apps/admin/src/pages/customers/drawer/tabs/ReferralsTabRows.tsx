import { Badge } from '@/components/ui/badge';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
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
import { DRAWER_ROW_CLASS, drawerCell, DrawerTable } from './DrawerTable';

export function ReferralsTabRows({ rows, isLoading }: { rows: ReferralRow[]; isLoading: boolean }) {
  return (
    <DrawerTable
      columnCount={6}
      isLoading={isLoading}
      isEmpty={rows.length === 0}
      emptyTitle={EMPTY_REFERRALS_TITLE()}
      emptyBody={EMPTY_REFERRALS_BODY()}
      head={
        <TableRow>
          <TableHead>{COLUMN_REFERRED()}</TableHead>
          <TableHead>{COLUMN_SIGNED_UP()}</TableHead>
          <TableHead>{COLUMN_FIRST_ORDER()}</TableHead>
          <TableHead className="text-right">{COLUMN_TO_REFERRER()}</TableHead>
          <TableHead className="text-right">{COLUMN_TO_FRIEND()}</TableHead>
          <TableHead className="text-right">{COLUMN_REFERRAL_STATUS()}</TableHead>
        </TableRow>
      }
    >
      {rows.map((row, index) => (
        <TableRow key={index} className={DRAWER_ROW_CLASS}>
          <TableCell className={drawerCell(1)}>
            <div className="font-medium">{row.referredName}</div>
            <div className="text-muted-foreground text-xs">{row.referredEmail}</div>
          </TableCell>
          <TableCell className={drawerCell(2)}>{row.signedUpAt}</TableCell>
          <TableCell className={drawerCell(3)}>{row.firstOrder}</TableCell>
          <TableCell className={drawerCell(4, 'text-right tabular-nums')}>{row.referrerPoints}</TableCell>
          <TableCell className={drawerCell(5, 'text-muted-foreground text-right')}>{row.friendCoupon}</TableCell>
          <TableCell className={drawerCell(6, 'text-right')}>
            <Badge variant="outline">{row.status}</Badge>
            {row.statusNote && <div className="text-muted-foreground mt-1 text-xs">{row.statusNote}</div>}
          </TableCell>
        </TableRow>
      ))}
    </DrawerTable>
  );
}
