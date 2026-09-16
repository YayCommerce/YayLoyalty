import { z } from 'zod';

import { api } from '@/lib/api/api';
import { campaignTriggerSchema } from '@/lib/schema/campaign.schema';

/**
 * Client for GET /campaigns/table.
 *
 * Lives under `pro/` because the route only exists in a Pro build — Lite
 * returns 404. Reached only through the `@/pro` barrel, so a Lite build drops
 * this file with the rest of the subtree.
 *
 * Mirrors Pro\Controllers\CampaignTableRestController, which is itself a
 * dummy fixture — see that class's docblock. `gives` / `conditions_label` /
 * `schedule_label` are pre-formatted display strings rather than structured
 * data because there is nothing real behind them yet to format from.
 */

/**
 * Presentation-only. Unlike the real, stored `CampaignStatus`, this includes
 * "scheduled" — normally derived client-side from status + date window, but
 * this fixture has no dates to derive it from, so the server hands back the
 * already-resolved display value.
 */
export const CAMPAIGN_DISPLAY_STATUSES = ['active', 'scheduled', 'paused', 'draft', 'archived'] as const;

export const campaignDisplayStatusSchema = z.enum(CAMPAIGN_DISPLAY_STATUSES);

export type CampaignDisplayStatus = z.infer<typeof campaignDisplayStatusSchema>;

export const campaignTableRowSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  trigger: campaignTriggerSchema,
  gives: z.string(),
  conditions_label: z.string(),
  schedule_label: z.string(),
  priority: z.number().int(),
  display_status: campaignDisplayStatusSchema,
  is_builtin: z.boolean(),
  /**
   * Points issued in the last 30 days. Whether this is even shown depends on
   * `display_status`, not on the number itself — see formatPoints(). Null on
   * a row the display rule would dash out anyway; never null for `active`.
   */
  points_last_30_days: z.number().int().nullable(),
});

export type CampaignTableRow = z.infer<typeof campaignTableRowSchema>;

const campaignTableSchema = z.object({
  data: z.array(campaignTableRowSchema),
});

/**
 * SWAP POINT. Fetches a real endpoint that returns a hardcoded fixture — see
 * CampaignTableRestController::fixture(). Swapping that PHP method for a real
 * repository-backed read is the entire change; this function and everything
 * above and below it stay as-is.
 */
export async function getCampaignTable(): Promise<CampaignTableRow[]> {
  const payload = await api.get('campaigns/table').json();

  return campaignTableSchema.parse(payload).data;
}

/**
 * A campaign not running long enough to have a meaningful figure — draft,
 * paused, scheduled — dashes regardless of what `points_last_30_days` holds.
 * Archived is deliberately not here: an archived campaign ran before being
 * retired, so its historical figure is real and worth showing (see
 * CAMPAIGN_DISPLAY_STATUSES's docblock for where archived rows are hidden
 * instead — filtered out of the table and summary rather than dashed here).
 */
const STATUSES_WITHOUT_A_FIGURE: readonly CampaignDisplayStatus[] = ['draft', 'paused', 'scheduled'];

/**
 * The display figure — "—" per STATUSES_WITHOUT_A_FIGURE, else abbreviated
 * above a million (`1.2M`). The exact value stays available via
 * formatPointsExact(); pair the two in a tooltip rather than showing only
 * the rounded one, same as "the exact value on the campaign's own page".
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
