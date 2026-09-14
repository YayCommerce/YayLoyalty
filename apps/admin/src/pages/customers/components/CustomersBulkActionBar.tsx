import { sprintf, __ } from '@wordpress/i18n';
import { ChevronsUpDown } from 'lucide-react';

import { CAP, Can } from '@/lib/access';
import { BulkActionBox } from '@/components/ui/bulk-actions';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';

/**
 * Matches YayUiKits' `BulkActionBox` pattern exactly — same component,
 * same "Bulk Actions" dropdown trigger (see
 * `YayUiKits/apps/admin-page/src/components/ui/bulk-actions.tsx` and its
 * `routes/patterns/TablePage.tsx` demo). The "N selected" pill only appears
 * once more than one row is picked — that threshold lives inside
 * `BulkActionBox` itself, not re-decided here. Every action in the dropdown
 * is still `<Can>`-gated and performs no action — UI only.
 *
 * "Select all matching filters" is real (see CustomersScreen.tsx) — folded
 * into this same bordered box instead of a second banner tier, so selection
 * stays one row like the kit's rather than growing its own two-tier shape.
 */
export function CustomersBulkActionBar({
  count,
  onClear,
  selectAllCount,
  onSelectAllMatching,
}: {
  count: number;
  onClear: () => void;
  /** Present only when there's a larger filtered set beyond this page to expand into. */
  selectAllCount?: number;
  onSelectAllMatching?: () => void;
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
            <DropdownMenuItem>{__('Adjust points', 'yayloyalty')}</DropdownMenuItem>
          </Can>
          <Can cap={CAP.MANAGE_TIERS} disable>
            <DropdownMenuItem>{__('Assign tier', 'yayloyalty')}</DropdownMenuItem>
          </Can>
          <Can cap={CAP.MANAGE_POINTS} disable>
            <DropdownMenuItem>{__('Reset expiry', 'yayloyalty')}</DropdownMenuItem>
          </Can>
          <Can cap={CAP.VIEW} disable>
            <DropdownMenuItem>{__('Export', 'yayloyalty')}</DropdownMenuItem>
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
