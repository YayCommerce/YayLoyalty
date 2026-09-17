import { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';

/**
 * "Points issued in the last 30 days", per campaign id — CampaignTable's and
 * CampaignPointsSummary's only dummy data. Everything else on those two
 * components comes from the real campaign list (`useCampaignsQuery()`); this
 * one figure does not exist on the real `Campaign` shape and has nothing to
 * come from yet — no endpoint yet exposes a per-campaign 30-day figure for
 * the whole list (Pro CampaignPointsRepository::issued_by_campaign() feeds
 * only the dashboard's top-3). Rather than fake it on the server (which
 * would mean either bolting a presentation field onto the canonical schema,
 * or a second endpoint/fixture), it lives here as a plain client-side map,
 * keyed to the same dummy ids CampaignRestController::dummy_fixture() uses.
 *
 * SWAP POINT. Once `GET /campaigns` surfaces a real per-campaign
 * points-issued aggregate for the whole list, this file is deleted and
 * toDisplayCampaign() in CampaignTable.tsx reads the real figure instead of
 * this map — everything
 * downstream (hasPointsFigure(), `<PointsValue>`, CampaignPointsSummary) is
 * already written against "a number or null", so nothing else changes.
 *
 * Formatting itself is NOT reimplemented here — `@/lib/helpers/points.helper`
 * (compacting, the 100k+ threshold) and `<PointsValue>`
 * (`@/components/ui/custom/points-value`, compact + hover-for-exact) already
 * do that for every points figure in the admin. This file only adds the one
 * thing genuinely specific to a campaign: whether its display status even
 * has a meaningful figure to show.
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

/**
 * A campaign not running long enough to have a meaningful figure — draft,
 * paused, scheduled — has nothing worth showing regardless of what the map
 * holds; render "—" instead of calling `<PointsValue>`. Archived is
 * deliberately not here: an archived campaign ran before being retired, so
 * its historical figure is real and worth showing (archived rows are
 * filtered out of the table and summary elsewhere, not dashed here).
 */
const STATUSES_WITHOUT_A_FIGURE: readonly CampaignDisplayStatus[] = ['draft', 'paused', 'scheduled'];

/** Whether this display status has a meaningful "last 30 days" figure at all — the dash decision, not the number's formatting. */
export function hasPointsFigure(status: CampaignDisplayStatus): boolean {
  return !STATUSES_WITHOUT_A_FIGURE.includes(status);
}
