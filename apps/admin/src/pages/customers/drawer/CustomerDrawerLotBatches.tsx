import { parseWPDay } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { DrawerExpiry } from '@/lib/schema/customer.schema';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { isProBuild } from '@/components/pro/ProGate';
import { COLUMN_POINTS } from './customer-drawer.copy';
import {
  EXPIRY_BATCH_COLUMN_EARNED,
  EXPIRY_BATCH_COLUMN_EXPIRES,
  EXPIRY_BATCH_TITLE,
  EXPIRY_FREE_EXPLANATION,
} from './customer-drawer.panels.copy';

/**
 * 4b's Pro FIFO lot table — "his points, by the batch they were earned in".
 * Gated on `isProBuild()` directly rather than `<ProGate>`: `ProGate`'s
 * overlay is a generic upsell, but 4b's own note calls for a SPECIFIC
 * explanation ("On the free inactivity model there are no batches"), not a
 * sales pitch — so Lite renders that sentence in the table's place instead
 * of `ProGate`'s `UpgradeToProOverlay`.
 *
 * `batches: null` (the wire contract's OTHER "no batches" case — a store on
 * the inactivity model regardless of build) gets the same explanation:
 * nothing on the real path sends it today, but the component still handles
 * it correctly rather than rendering an empty table.
 */
export function CustomerDrawerLotBatches({ batches }: { batches: DrawerExpiry['batches'] }) {
  if (!isProBuild() || !batches) {
    return (
      <div className="rounded-md border p-3.5">
        <p className="text-muted-foreground text-xs">{EXPIRY_FREE_EXPLANATION()}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm font-medium">{EXPIRY_BATCH_TITLE()}</p>
      <div className="overflow-x-auto rounded-md border">
        <Table className="min-w-max">
          <TableHeader>
            <TableRow>
              <TableHead>{EXPIRY_BATCH_COLUMN_EARNED()}</TableHead>
              <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
              <TableHead className="text-right">{EXPIRY_BATCH_COLUMN_EXPIRES()}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {batches.map((batch, index) => (
              <TableRow key={index}>
                <TableCell>
                  {parseWPDay(batch.earned_from)} – {parseWPDay(batch.earned_to)}
                </TableCell>
                <TableCell className="text-right tabular-nums">{formatPoints(batch.points)}</TableCell>
                <TableCell className="text-muted-foreground text-right tabular-nums">
                  {parseWPDay(batch.expires_at)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
