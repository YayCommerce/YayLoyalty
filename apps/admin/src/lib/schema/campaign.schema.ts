import { z } from 'zod';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';

/** Mirrors CampaignRestController::define_item_schema(), the source of truth. */

/** Mirrors CampaignTrigger::lite_triggers(). */
export const LITE_CAMPAIGN_TRIGGERS = ['purchase', 'signup', 'review'] as const;

/** Mirrors YayLoyaltyPro::ADDED_TRIGGERS. */
export const PRO_CAMPAIGN_TRIGGERS = ['birthday', 'first_order', 'order_count', 'spend_milestone'] as const;

/**
 * Every trigger the API may hand back, mirroring CampaignTrigger::all_known().
 * Parsing must accept Pro values in both builds, or a downgraded site's
 * campaigns list throws instead of rendering.
 */
export const ALL_KNOWN_CAMPAIGN_TRIGGERS = [
  ...LITE_CAMPAIGN_TRIGGERS,
  ...PRO_CAMPAIGN_TRIGGERS,
  'referral',
  'social',
  'renewal',
] as const;

/** Triggers this build offers, mirroring CampaignTrigger::all(). For UI choices only, never parsing. */
export const CAMPAIGN_TRIGGERS = IS_PRO_BUILD
  ? ([...LITE_CAMPAIGN_TRIGGERS, ...PRO_CAMPAIGN_TRIGGERS] as const)
  : LITE_CAMPAIGN_TRIGGERS;

/** Stored statuses, mirroring Enums\Status::all(). No scheduled/ended: liveness is derived. */
export const CAMPAIGN_STATUSES = ['active', 'paused', 'draft', 'archived'] as const;

/** Parses the full known vocabulary, in both builds. */
export const campaignTriggerSchema = z.enum(ALL_KNOWN_CAMPAIGN_TRIGGERS);
export const campaignStatusSchema = z.enum(CAMPAIGN_STATUSES);

export type CampaignTrigger = z.infer<typeof campaignTriggerSchema>;

/** A trigger this build can offer, as opposed to one it merely recognises. */
export type OfferableCampaignTrigger = (typeof CAMPAIGN_TRIGGERS)[number];
export type CampaignStatus = z.infer<typeof campaignStatusSchema>;

/** CampaignStatus plus the two derived, never-stored labels. Mirrors Enums\Status::resolve_display(). */
export const CAMPAIGN_DISPLAY_STATUSES = [...CAMPAIGN_STATUSES, 'scheduled', 'ended'] as const;

export type CampaignDisplayStatus = (typeof CAMPAIGN_DISPLAY_STATUSES)[number];

/**
 * Mirrors Enums\Status::resolve_display(). `at` is UTC in the stored shape;
 * pass the campaign's dates through unconverted.
 */
export function resolveCampaignDisplayStatus(
  status: CampaignStatus,
  startsAt: string | null,
  endsAt: string | null,
  at: string = new Date().toISOString().slice(0, 19).replace('T', ' '),
): CampaignDisplayStatus {
  if (status !== 'active') {
    return status;
  }

  if (startsAt && startsAt > at) {
    return 'scheduled';
  }

  if (endsAt && endsAt <= at) {
    return 'ended';
  }

  return 'active';
}

export const campaignSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** Generated server-side and immutable after create. */
  slug: z.string(),
  trigger: campaignTriggerSchema,
  status: campaignStatusSchema,
  priority: z.number().int(),
  /** Loose on purpose: the per-trigger whitelist lives in PHP and would drift if duplicated. */
  config: z.record(z.string(), z.unknown()),
  conditions: z.union([z.record(z.string(), z.unknown()), z.array(z.unknown())]),
  starts_at: z.string().nullable(),
  /** Exclusive: a campaign is not live at exactly this instant. */
  ends_at: z.string().nullable(),
  /** Built-ins cannot be deleted and are addressed by slug across sites. */
  is_builtin: z.boolean(),
  created_by: z.number().int().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
});

export type Campaign = z.infer<typeof campaignSchema>;

/** App-side list params; campaign.api.ts maps them to the wire. */
export const campaignListParamsSchema = z.object({
  page: z.number().int().optional(),
  perPage: z.number().int().optional(),
  trigger: campaignTriggerSchema.optional(),
  status: campaignStatusSchema.optional(),
  search: z.string().optional(),
});

export type CampaignListParams = z.infer<typeof campaignListParamsSchema>;
