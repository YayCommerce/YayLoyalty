import type { ReactNode } from 'react';

import type { ExtraSeries } from './components/DashboardPointsChart';

/**
 * What the Pro tier adds to the dashboard, as slots the Free layout already
 * has room for: the screen grows, nothing is rearranged.
 * Pro fills them from `@/pro/dashboard/DashboardProPanels`; Lite fills them
 * with `<ProGate inline />` teasers (DashboardProTeasers). The page renders
 * the same tree either way.
 */
export type DashboardProSlots = {
  /** Sixth tile, appended to the stats grid. */
  referralsTile: ReactNode;
  /** Header controls beside the range selector. */
  chartControls: ReactNode;
  /** Thin per-campaign lines when the breakdown is on. */
  extraSeries: ExtraSeries[];
  /** Tier distribution + Top campaigns, under Top earners. */
  sidePanels: ReactNode;
};
