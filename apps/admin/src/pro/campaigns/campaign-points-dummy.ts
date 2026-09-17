import { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';

/**
 * Dummy "points issued in the last 30 days" per campaign, keyed to
 * CampaignRestController::dummy_fixture() ids, until an endpoint exposes
 * the figure. Delete this file and read the real figure in
 * toDisplayCampaign(); everything downstream already handles number | null.
 */
export const DUMMY_POINTS_LAST_30_DAYS: Record<number, number | null> = {
  1: 38600, // Summit+ members 1.5×
  2: null, // Black Friday 2× — scheduled, hasn't run yet
  3: null, // Trail Shoes boost — paused
  4: 121400, // Points for purchases (built-in)
  5: 62150, // Points for signing up (built-in)
  6: 9300, // Points for writing a review (built-in)
  7: null, // Birthday points — draft
  8: null, // Spring Sale 1.5× — archived
  9: 1240000, // VIP double points — above a million on purpose, see formatCompactPoints()
  10: 5200,
  11: 3100,
  12: null, // Weekend flash 1.3× — paused
  13: null, // Clearance category boost — draft
  14: 1200,
  15: 800,
  16: 640,
  17: null, // Video review bonus — paused
  18: 4200,
  19: 980,
  20: null, // Big spender milestone — scheduled
  21: null, // Anniversary bonus — draft
  22: 2100,
};

/** Statuses with no meaningful figure. Archived is not here: it ran before retiring, so its figure is real. */
const STATUSES_WITHOUT_A_FIGURE: readonly CampaignDisplayStatus[] = ['draft', 'paused', 'scheduled'];

/** Whether a display status has a meaningful "last 30 days" figure. */
export function hasPointsFigure(status: CampaignDisplayStatus): boolean {
  return !STATUSES_WITHOUT_A_FIGURE.includes(status);
}
