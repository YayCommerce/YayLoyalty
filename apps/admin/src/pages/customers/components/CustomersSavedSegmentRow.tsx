import { ChevronDown } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';

/**
 * Pro-only saved-segment picker shown above the toolbar. No real segment
 * feature exists yet, so it always reads "All members" (the neutral default
 * — no segment applied) rather than a canned example name.
 */
export function CustomersSavedSegmentRow({ disabled }: { disabled?: boolean }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-muted-foreground text-xs">{__('Saved segment', 'yayloyalty')}</span>
      <Button variant="outline" size="sm" disabled={disabled}>
        {__('All members', 'yayloyalty')}
        <ChevronDown />
      </Button>
      <Button variant="link" size="sm" disabled={disabled}>
        {__('Save current filters as segment', 'yayloyalty')}
      </Button>
    </div>
  );
}
