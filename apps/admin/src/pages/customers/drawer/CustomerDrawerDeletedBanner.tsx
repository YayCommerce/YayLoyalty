import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { deletedBannerBody, deletedBannerTitle } from './customer-drawer.panels.copy';

/**
 * 4a's banner: the deletion date, then why the ledger survives it.
 * `CustomerDrawer.tsx` only renders this (and its two sibling panels) once
 * `customer.deleted_at` and `customer.loose_ends` are both non-null — the
 * real path can never satisfy that today (no `deleted_at` column).
 */
export function CustomerDrawerDeletedBanner({ customer }: { customer: Customer }) {
  if (!customer.deleted_at || !customer.loose_ends) return null;

  return (
    <div className="bg-muted/50 flex flex-col gap-1 rounded-md border px-3.5 py-2.5">
      <p className="text-sm font-medium">{deletedBannerTitle(formatStoredDate(customer.deleted_at))}</p>
      <p className="text-muted-foreground text-xs">
        {deletedBannerBody(formatPoints(customer.loose_ends.kept_ledger_entries))}
      </p>
    </div>
  );
}
