import { sprintf, __ } from '@wordpress/i18n';

import { CAP, Can } from '@/lib/access';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';

/**
 * Pro bulk action bar. Appears in flow above the table on first selection —
 * never floats or overlays. Every button is `<Can>`-gated per the capability
 * mapping below and performs no action — UI only.
 */
export function CustomersBulkActionBar({ count, onClear }: { count: number; onClear: () => void }) {
  return (
    <div className="bg-primary/6 flex flex-wrap items-center gap-3 rounded-md px-4 py-2.5">
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
  );
}
