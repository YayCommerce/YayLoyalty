import { __ } from '@wordpress/i18n';

import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { ProGate } from '@/components/pro/ProGate';

/** Totals | Break down by campaign — the header control beside the range selector (artboard 1b #2). */
export function DashboardCampaignBreakdownToggle({ on, onChange }: { on: boolean; onChange: (on: boolean) => void }) {
  return (
    <div className="flex items-center gap-2">
      <Segmented size="sm" value={on ? 'campaigns' : 'totals'} onValueChange={(v) => v && onChange(v === 'campaigns')}>
        <SegmentedItem value="totals">{__('Totals', 'yayloyalty')}</SegmentedItem>
        <SegmentedItem value="campaigns">{__('Break down by campaign', 'yayloyalty')}</SegmentedItem>
      </Segmented>
      <ProGate inline />
    </div>
  );
}
