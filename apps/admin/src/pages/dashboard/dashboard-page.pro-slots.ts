import type { ReactNode } from 'react';

import type { ExtraSeries } from './components/DashboardPointsChart';

/** What Pro adds to the dashboard, as slots the Lite layout has room for; Lite fills them with teasers. */
export type DashboardProSlots = {
  /** Header controls beside the range selector. */
  chartControls: ReactNode;
  /** Thin per-campaign lines when the breakdown is on. */
  extraSeries: ExtraSeries[];
  /** Top campaigns, under Top earners. */
  sidePanels: ReactNode;
};
