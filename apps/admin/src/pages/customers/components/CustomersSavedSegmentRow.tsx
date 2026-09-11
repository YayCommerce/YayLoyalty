import { ChevronDown } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { ProGate } from '@/components/pro/ProGate';

/** Pro-only saved-segment picker shown above the toolbar. */
export function CustomersSavedSegmentRow({ valueLabel, saveLinkLabel }: { valueLabel: string; saveLinkLabel: string }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-muted-foreground text-xs">{__('Saved segment', 'yayloyalty')}</span>
      <Button variant="outline" size="sm">
        {valueLabel}
        <ChevronDown />
      </Button>
      <Button variant="link" size="sm">
        {saveLinkLabel}
      </Button>
      <ProGate inline />
    </div>
  );
}
