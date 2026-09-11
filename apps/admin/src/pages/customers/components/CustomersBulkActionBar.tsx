import { __ } from '@wordpress/i18n';

import { CAP, Can } from '@/lib/access';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';

/**
 * Pro bulk action bar. Appears in flow above the table on first selection —
 * never floats or overlays. Optional second tier (2f) distinguishes "this
 * page" selection from "every customer matching the current filters".
 *
 * Every button is `<Can>`-gated per the capability mapping below and performs
 * no action — UI only.
 */
export function CustomersBulkActionBar({
  selectedLabel,
  coversPageNote,
  selectAllLabel,
  onClear,
}: {
  selectedLabel: string;
  coversPageNote?: string;
  selectAllLabel?: string;
  onClear: () => void;
}) {
  return (
    <div className="border-primary flex flex-col rounded-md border">
      <div className="bg-primary/6 flex flex-wrap items-center gap-3 rounded-t-md px-4 py-2.5">
        <span className="text-sm font-semibold">{selectedLabel}</span>
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
      {coversPageNote && (
        <div className="border-primary/30 flex flex-wrap items-center gap-3 border-t px-4 py-2 text-xs">
          <span>{coversPageNote}</span>
          <Button variant="link" size="sm">
            {selectAllLabel}
          </Button>
        </div>
      )}
    </div>
  );
}
