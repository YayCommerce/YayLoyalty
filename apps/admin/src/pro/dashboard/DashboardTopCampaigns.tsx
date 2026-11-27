import { Megaphone } from 'lucide-react';
import { Link } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import type { DashboardTopCampaign } from '@/lib/schema/dashboard-pro.schema';
import type { DashboardRange } from '@/lib/schema/dashboard.schema';
import { PointsValue } from '@/components/ui/custom/points-value';
import { ProGate } from '@/components/pro/ProGate';
import { DashboardInitialsAvatar } from '@/pages/dashboard/components/DashboardInitialsAvatar';
import { DashboardPanel, DashboardPanelLink } from '@/pages/dashboard/components/DashboardPanel';
import { RANGE_LABEL } from '@/pages/dashboard/dashboard-page.copy';
import { conditionsLabel, givesLabel } from '../campaigns/campaign-labels';

/** Ranked rows — name / points / "gives · priority N · conditions", composed with the Campaigns page's own label helpers. */
export function DashboardTopCampaigns({
  campaigns,
  range,
}: {
  campaigns: DashboardTopCampaign[];
  range: DashboardRange;
}) {
  return (
    <DashboardPanel
      title={
        <>
          {__('Top campaigns', 'yayloyalty')}
          <ProGate inline />
        </>
      }
      note={sprintf(
        /* translators: %s: the range's length, e.g. "30 days". */
        __('points issued, last %s', 'yayloyalty'),
        RANGE_LABEL(range),
      )}
      action={
        <Can cap={CAP.MANAGE_CAMPAIGNS}>
          <DashboardPanelLink to="/campaigns">{__('View campaigns', 'yayloyalty')}</DashboardPanelLink>
        </Can>
      }
    >
      <ul className="m-0 flex flex-col gap-1 px-6 py-3">
        {campaigns.length === 0 ? (
          <li className="text-muted-foreground m-0 py-3 text-sm">
            {__('No campaign has issued points yet.', 'yayloyalty')}
          </li>
        ) : (
          campaigns.map((c, i) => (
            <li key={c.campaign_id} className="m-0 flex items-center gap-3 py-2.5 text-sm">
              <DashboardInitialsAvatar
                name={c.name}
                label={String(i + 1).padStart(2, '0')}
                rank={i + 1}
                badgeIcon={<Megaphone />}
              />
              <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <Can cap={CAP.MANAGE_CAMPAIGNS} fallback={<span className="truncate font-medium">{c.name}</span>}>
                  <Link
                    to={`/campaigns/${c.campaign_id}/edit`}
                    className="text-foreground truncate font-medium hover:underline"
                  >
                    {c.name}
                  </Link>
                </Can>
                <span className="text-muted-foreground truncate text-xs">
                  {[
                    givesLabel(c.trigger, c.config),
                    sprintf(
                      /* translators: %d: the campaign's priority number. */
                      __('priority %d', 'yayloyalty'),
                      c.priority,
                    ),
                    conditionsLabel(c.conditions),
                  ].join(' · ')}
                </span>
              </div>
              <PointsValue value={c.points} className="font-semibold tabular-nums" />
            </li>
          ))
        )}
      </ul>
    </DashboardPanel>
  );
}
