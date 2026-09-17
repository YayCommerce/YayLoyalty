import { ChevronsUpDown } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { BulkActionBox } from '@/components/ui/bulk-actions';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';

/**
 * Matches YayUiKits' `BulkActionBox` pattern exactly — same component,
 * same "Bulk Actions" dropdown trigger (see
 * `YayUiKits/apps/admin-page/src/components/ui/bulk-actions.tsx` and its
 * `routes/patterns/TablePage.tsx` demo). The "N selected" pill only appears
 * once at least one row is picked — that threshold (`selected > 0`) lives
 * inside `BulkActionBox` itself, not re-decided here.
 *
 * "Adjust points" and "Export" are real (see `customers-page.bulk-actions.ts`
 * / `customers-page.export.ts`). "Assign tier" and "Reset expiry" are NOT
 * offered here: the former has no tiers table or assign-tier endpoint yet
 * (`TIER_FILTER_ENABLED` is on — see `customers-page.filter-rows.ts` — but
 * nothing writes a real per-customer tier), the latter has no REST endpoint
 * at all yet — showing either would be a control that does nothing when
 * clicked, worse than not showing it.
 *
 * "Select all matching filters" is real (see customers-page.selection.ts) —
 * an Ant-Design-Table-style mode flip ("everything matching, minus any you
 * un-check"), not a fetch of every matching row, so it stays instant no
 * matter how large the real list gets. Folded into this same bordered box
 * instead of a second banner tier, so selection stays one row like the
 * kit's rather than growing its own two-tier shape.
 */
export function CustomersBulkActionBar({
  count,
  onClear,
  selectAllCount,
  onSelectAllMatching,
  onAdjustPoints,
  onExport,
  isExporting,
}: {
  count: number;
  onClear: () => void;
  /** Present only when there's a larger filtered set beyond this page to expand into. */
  selectAllCount?: number;
  onSelectAllMatching?: () => void;
  onAdjustPoints: () => void;
  onExport: () => void;
  isExporting: boolean;
}) {
  return (
    <BulkActionBox selected={count} onClose={onClear}>
      <span className="text-sm font-semibold">{sprintf(__('%d selected', 'yayloyalty'), count)}</span>
      <Separator orientation="vertical" className="ml-2 h-5!" />
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            className="hover:text-primary hover:bg-primary/6 group flex cursor-pointer items-center gap-1.5 px-2.5 pe-2 font-normal"
          >
            <span>{__('Bulk Actions', 'yayloyalty')}</span>
            <span className="group-hover:text-primary text-muted-foreground flex items-center">
              <ChevronsUpDown className="size-3.5 stroke-[2.5px]" />
            </span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" sideOffset={9} className="w-fit min-w-40">
          <Can cap={CAP.MANAGE_POINTS} disable>
            <DropdownMenuItem onSelect={onAdjustPoints}>{__('Adjust points', 'yayloyalty')}</DropdownMenuItem>
          </Can>
          <Can cap={CAP.VIEW} disable>
            <DropdownMenuItem disabled={isExporting} onSelect={onExport}>
              {isExporting ? __('Exporting…', 'yayloyalty') : __('Export', 'yayloyalty')}
            </DropdownMenuItem>
          </Can>
        </DropdownMenuContent>
      </DropdownMenu>
      {selectAllCount !== undefined && (
        <>
          <Separator orientation="vertical" className="h-5!" />
          <Button variant="link" size="sm" className="font-normal" onClick={onSelectAllMatching}>
            {sprintf(__('Select all %d matching these filters', 'yayloyalty'), selectAllCount)}
          </Button>
        </>
      )}
    </BulkActionBox>
  );
}
