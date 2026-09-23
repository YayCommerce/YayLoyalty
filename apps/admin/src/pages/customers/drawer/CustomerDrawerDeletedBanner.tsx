import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { deletedBannerBody, deletedBannerTitle } from './customer-drawer.panels.copy';

/** 4a's banner: the deletion date, then why the ledger survives it. */
export function CustomerDrawerDeletedBanner({ deletedAt, keptEntries }: { deletedAt: string; keptEntries: number }) {
  return (
    <div className="bg-muted/50 flex flex-col gap-1 rounded-md border px-3.5 py-2.5">
      <p className="text-sm font-medium">{deletedBannerTitle(formatStoredDate(deletedAt))}</p>
      <p className="text-muted-foreground text-xs">{deletedBannerBody(formatPoints(keptEntries))}</p>
    </div>
  );
}
