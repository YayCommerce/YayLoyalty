import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { ProGate } from '@/components/pro/ProGate';
import type { DashboardProSlots } from '../dashboard-page.pro-slots';
import { DashboardPanel } from './DashboardPanel';

/** Lite's view of the Pro slots: same positions with the PRO badge. Never imports from @/pro. */
export function DashboardProTeasers({ children }: { children: (slots: DashboardProSlots) => ReactNode }) {
  return (
    <>
      {children({
        chartControls: (
          <span className="text-muted-foreground flex items-center gap-2 text-xs">
            {__('By campaign', 'yayloyalty')}
            <ProGate inline />
          </span>
        ),
        extraSeries: [],
        sidePanels: (
          <DashboardPanel
            title={
              <>
                {__('Top campaigns', 'yayloyalty')}
                <ProGate inline />
              </>
            }
          >
            <p className="text-muted-foreground m-0 px-6 py-4 text-sm">
              {__('Which campaigns issue the most points.', 'yayloyalty')}
            </p>
          </DashboardPanel>
        ),
      })}
    </>
  );
}
