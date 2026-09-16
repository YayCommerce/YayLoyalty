import { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';

/**
 * "Points issued in the last 30 days", per campaign id — CampaignTable's and
 * CampaignPointsSummary's only dummy data. Everything else on those two
 * components comes from the real campaign list (`useCampaignsQuery()`); this
 * one figure does not exist on the real `Campaign` shape and has nothing to
 * come from yet — no store-wide or per-campaign ledger aggregate exists
 * (LedgerRepository's sum_points_for_user() is per-customer only). Rather
 * than fake it on the server (which would mean either bolting a presentation
 * field onto the canonical schema, or a second endpoint/fixture), it lives
 * here as a plain client-side map, keyed to the same dummy ids
 * CampaignRestController::dummy_fixture() uses.
 *
 * SWAP POINT. Once a real per-campaign points-issued aggregate exists (a
 * ledger-backed endpoint, most likely `GROUP BY campaign_id` over `earn`
 * rows in the last 30 days), this file is deleted and toDisplayCampaign() in
 * CampaignTable.tsx reads the real figure instead of this map — everything
 * downstream (formatPoints() and friends, CampaignPointsSummary) is already
 * written against "a number or null", so nothing else changes.
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
  9: 1240000, // VIP double points — above a million on purpose, see abbreviate()
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
 * paused, scheduled — dashes regardless of what the map holds. Archived is
 * deliberately not here: an archived campaign ran before being retired, so
 * its historical figure is real and worth showing (archived rows are
 * filtered out of the table and summary elsewhere, not dashed here).
 */
const STATUSES_WITHOUT_A_FIGURE: readonly CampaignDisplayStatus[] = ['draft', 'paused', 'scheduled'];

/**
 * The display figure — "—" per STATUSES_WITHOUT_A_FIGURE, else abbreviated
 * above a million (`1.2M`). The exact value stays available via
 * formatPointsExact(); pair the two in a tooltip rather than showing only
 * the rounded one.
 */
export function formatPoints(points: number | null, status: CampaignDisplayStatus): string {
  return STATUSES_WITHOUT_A_FIGURE.includes(status) ? '—' : abbreviate(points ?? 0);
}

/** The un-rounded counterpart to formatPoints() — same dash rule, full number otherwise. */
export function formatPointsExact(points: number | null, status: CampaignDisplayStatus): string {
  return STATUSES_WITHOUT_A_FIGURE.includes(status) ? '—' : (points ?? 0).toLocaleString();
}

/** Whether formatPoints() rounded this figure — i.e., whether showing the exact value is worth a tooltip. */
export function isPointsAbbreviated(points: number | null, status: CampaignDisplayStatus): boolean {
  return formatPoints(points, status) !== formatPointsExact(points, status);
}

function abbreviate(value: number): string {
  if (value < 1_000_000) {
    return value.toLocaleString();
  }

  return `${(value / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 1 })}M`;
}
