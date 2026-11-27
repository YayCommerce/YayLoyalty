import { CheckCheck, XIcon } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { Button } from '@/components/ui/button';
import { EXPORTING_LABEL } from '../customers-page.export.copy';

/** "Reset expiry" is left out until it has an endpoint: a dead control is worse than none. */
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
  if (count === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="flex items-center gap-1.5 text-sm">
        <CheckCheck className="text-muted-foreground size-4" />
        {sprintf(
          /* translators: %d: number of selected customers. */
          __('%d selected', 'yayloyalty'),
          count,
        )}
      </span>
      <Button
        variant="ghost"
        size="icon-sm"
        className="text-muted-foreground size-7"
        aria-label={__('Clear selection', 'yayloyalty')}
        onClick={onClear}
      >
        <XIcon />
      </Button>
      <Can cap={CAP.MANAGE_POINTS} disable>
        <Button onClick={onAdjustPoints}>{__('Adjust points', 'yayloyalty')}</Button>
      </Can>
      <Can cap={CAP.VIEW} disable>
        <Button variant="outline" disabled={isExporting} onClick={onExport}>
          {isExporting ? EXPORTING_LABEL() : __('Export CSV', 'yayloyalty')}
        </Button>
      </Can>
      {selectAllCount !== undefined && (
        <Button variant="link" size="sm" className="font-normal" onClick={onSelectAllMatching}>
          {sprintf(
            /* translators: %d: number of customers matching the current filters. */
            __('Select all %d matching these filters', 'yayloyalty'),
            selectAllCount,
          )}
        </Button>
      )}
    </div>
  );
}
