import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ProGate } from '@/components/pro/ProGate';
import type { DashboardProSlots } from '../dashboard-page.pro-slots';

/** Lite's view of the Pro slots: same positions with the PRO badge. Never imports from @/pro. */
export function DashboardProTeasers({ children }: { children: (slots: DashboardProSlots) => ReactNode }) {
  return (
    <>
      {children({
        chartControls: (
          <span className="text-muted-foreground flex items-center gap-2 text-xs">
            {__('Break down by campaign', 'yayloyalty')}
            <ProGate inline />
          </span>
        ),
        extraSeries: [],
        sidePanels: (
          <Card className="gap-1">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                {__('Top campaigns', 'yayloyalty')}
                <ProGate inline />
              </CardTitle>
              <CardDescription>{__('Which campaigns issue the most points.', 'yayloyalty')}</CardDescription>
            </CardHeader>
          </Card>
        ),
      })}
    </>
  );
}
