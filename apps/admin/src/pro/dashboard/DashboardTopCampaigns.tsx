import { __, sprintf } from '@wordpress/i18n';

import type { DashboardTopCampaign } from '@/lib/schema/dashboard-pro.schema';
import type { DashboardRange } from '@/lib/schema/dashboard.schema';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { PointsValue } from '@/components/ui/custom/points-value';
import { ProGate } from '@/components/pro/ProGate';
import { RANGE_LABEL } from '@/pages/dashboard/dashboard-page.copy';
import { conditionsLabel, givesLabel } from '../campaigns/campaign-labels';

/** Three rows — name / points / "gives · priority N · conditions", composed with the Campaigns page's own label helpers. */
export function DashboardTopCampaigns({
  campaigns,
  range,
}: {
  campaigns: DashboardTopCampaign[];
  range: DashboardRange;
}) {
  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {__('Top campaigns', 'yayloyalty')}
          <ProGate inline />
        </CardTitle>
        <CardDescription>{sprintf(__('points issued, last %s', 'yayloyalty'), RANGE_LABEL(range))}</CardDescription>
      </CardHeader>
      <ul className="flex flex-col divide-y">
        {campaigns.length === 0 ? (
          <li className="text-muted-foreground py-2 text-sm">
            {__('No campaign has issued points yet.', 'yayloyalty')}
          </li>
        ) : (
          campaigns.map((c) => (
            <li key={c.campaign_id} className="flex flex-col gap-0.5 py-2 text-sm">
              <div className="flex items-center justify-between gap-3">
                <span className="truncate font-medium">{c.name}</span>
                <PointsValue value={c.points} className="tabular-nums" />
              </div>
              <span className="text-muted-foreground truncate text-xs">
                {[
                  givesLabel(c.trigger, c.config),
                  sprintf(__('priority %d', 'yayloyalty'), c.priority),
                  conditionsLabel(c.conditions),
                ].join(' · ')}
              </span>
            </li>
          ))
        )}
      </ul>
    </Card>
  );
}
