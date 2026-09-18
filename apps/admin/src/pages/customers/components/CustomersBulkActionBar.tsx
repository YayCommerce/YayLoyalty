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
 * YayUiKits' BulkActionBox pattern. "Assign tier" and "Reset expiry" are not
 * offered: neither has an endpoint yet, and a dead control is worse than
 * none. "Select all matching" is folded into the same box so selection stays one row.
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
  /** Present only when there is a larger filtered set beyond this page. */
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
