import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ProGate } from '@/components/pro/ProGate';
import type { DashboardProSlots } from '../dashboard-page.pro-slots';
import { DashboardStatTile } from './DashboardStatTile';

/**
 * Lite's view of the four Pro surfaces (artboard 1a nav note): the same
 * positions, each carrying the PRO badge, so the pair of tiers reads as the
 * screen growing rather than being rearranged. Ships in both builds; never
 * imports from `@/pro`.
 */
export function DashboardProTeasers({ children }: { children: (slots: DashboardProSlots) => ReactNode }) {
  return (
    <>
      {children({
        referralsTile: (
          <DashboardStatTile
            label={__('Referrals', 'yayloyalty')}
            badge={<ProGate inline />}
            value="—"
            subline={__('completed, last 90 days', 'yayloyalty')}
            delta={null}
            range={90}
            deltaOverride={__('available in Pro', 'yayloyalty')}
          />
        ),
        chartControls: (
          <span className="text-muted-foreground flex items-center gap-2 text-xs">
            {__('Break down by campaign', 'yayloyalty')}
            <ProGate inline />
          </span>
        ),
        extraSeries: [],
        sidePanels: (
          <>
            <Card className="gap-1">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  {__('Tier distribution', 'yayloyalty')}
                  <ProGate inline />
                </CardTitle>
                <CardDescription>{__('See how members spread across your VIP tiers.', 'yayloyalty')}</CardDescription>
              </CardHeader>
            </Card>
            <Card className="gap-1">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  {__('Top campaigns', 'yayloyalty')}
                  <ProGate inline />
                </CardTitle>
                <CardDescription>{__('Which campaigns issue the most points.', 'yayloyalty')}</CardDescription>
              </CardHeader>
            </Card>
          </>
        ),
      })}
    </>
  );
}
