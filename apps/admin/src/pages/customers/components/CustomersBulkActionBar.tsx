import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { BulkActionBox } from '@/components/ui/bulk-actions';
import { Button } from '@/components/ui/button';
import { BulkActionsMenu } from '@/components/ui/custom/bulk-actions-menu';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { EXPORTING_LABEL } from '../customers-page.export.copy';

/** "Assign tier" and "Reset expiry" are left out until they have endpoints: a dead control is worse than none. */
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
      <BulkActionsMenu count={count} contentClassName="min-w-40">
        <Can cap={CAP.MANAGE_POINTS} disable>
          <DropdownMenuItem onSelect={onAdjustPoints}>{__('Adjust points', 'yayloyalty')}</DropdownMenuItem>
        </Can>
        <Can cap={CAP.VIEW} disable>
          <DropdownMenuItem disabled={isExporting} onSelect={onExport}>
            {isExporting ? EXPORTING_LABEL() : __('Export', 'yayloyalty')}
          </DropdownMenuItem>
        </Can>
      </BulkActionsMenu>
      {selectAllCount !== undefined && (
        <>
          <Separator orientation="vertical" className="h-5!" />
          <Button variant="link" size="sm" className="font-normal" onClick={onSelectAllMatching}>
            {sprintf(
              /* translators: %d: number of customers matching the current filters. */
              __('Select all %d matching these filters', 'yayloyalty'),
              selectAllCount,
            )}
          </Button>
        </>
      )}
    </BulkActionBox>
  );
}
