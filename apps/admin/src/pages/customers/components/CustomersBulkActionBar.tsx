import { sprintf, __ } from '@wordpress/i18n';

import { CAP, Can } from '@/lib/access';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';

/**
 * Pro bulk action bar. Appears in flow above the table on first selection —
 * never floats or overlays. Every action button is `<Can>`-gated per the
 * capability mapping below and performs no action — UI only.
 *
 * The second tier IS real: the header checkbox only ever selects the current
 * page (see CustomersScreen.tsx), so once there's more than one page and
 * something is selected, this offers expanding the selection to every row
 * matching the current filters — `selectAllCount` is the real filtered
 * total, not a canned number.
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
    <div className="flex flex-col rounded-md border border-transparent">
      <div className="bg-primary/6 flex flex-wrap items-center gap-3 rounded-t-md px-4 py-2.5">
        <span className="text-sm font-semibold">{sprintf(__('%d selected', 'yayloyalty'), count)}</span>
        <Separator orientation="vertical" className="h-4" />
        <Can cap={CAP.MANAGE_POINTS} disable>
          <Button variant="primary" size="sm">
            {__('Adjust points', 'yayloyalty')}
          </Button>
        </Can>
        <Can cap={CAP.MANAGE_TIERS} disable>
          <Button variant="outline" size="sm">
            {__('Assign tier', 'yayloyalty')}
          </Button>
        </Can>
        <Can cap={CAP.MANAGE_POINTS} disable>
          <Button variant="outline" size="sm">
            {__('Reset expiry', 'yayloyalty')}
          </Button>
        </Can>
        <Can cap={CAP.VIEW} disable>
          <Button variant="outline" size="sm">
            {__('Export', 'yayloyalty')}
          </Button>
        </Can>
        <Button variant="link" size="sm" className="ml-auto" onClick={onClear}>
          {__('Clear selection', 'yayloyalty')}
        </Button>
      </div>
      {selectAllCount !== undefined && (
        <div className="bg-primary/3 flex flex-wrap items-center gap-3 rounded-b-md px-4 py-2 text-xs">
          <span className="text-muted-foreground">{__('Selection covers this page only.', 'yayloyalty')}</span>
          <Button variant="link" size="sm" onClick={onSelectAllMatching}>
            {sprintf(__('Select all %d customers matching these filters', 'yayloyalty'), selectAllCount)}
          </Button>
        </div>
      )}
    </div>
  );
}
