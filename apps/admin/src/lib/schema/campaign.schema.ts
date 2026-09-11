import { z } from 'zod';

/**
 * Mirrors CampaignRestController::get_item_schema().
 *
 * THAT PHP METHOD IS THE SOURCE OF TRUTH.
 */

/**
 * Triggers Lite itself ships. Mirrors CampaignTrigger::lite_triggers().
 */
export const LITE_CAMPAIGN_TRIGGERS = ['purchase', 'signup', 'review'] as const;

/**
 * Triggers Pro adds. Mirrors YayLoyaltyPro::ADDED_TRIGGERS.
 */
export const PRO_CAMPAIGN_TRIGGERS = ['birthday', 'first_order', 'order_count', 'spend_milestone'] as const;

/**
 * Every trigger the API may hand back. Mirrors CampaignTrigger::all_known().
 *
 * THE SPA HALF OF DOWNGRADE SAFETY, and the easiest thing here to get wrong.
 * Parsing must accept Pro values in BOTH builds: a site that drops from Pro to
 * Lite still holds campaigns whose trigger is `birthday`, and a Lite bundle
 * that parsed against the offerable list alone would throw on the campaigns
 * list rather than render it. The PHP half (all_known() staying unfiltered) is
 * not enough on its own — zod would reject what PHP happily returned.
 *
 * `referral`, `social` and `renewal` are recognised for the same reason even
 * though no build offers them yet.
 */
export const ALL_KNOWN_CAMPAIGN_TRIGGERS = [
  ...LITE_CAMPAIGN_TRIGGERS,
  ...PRO_CAMPAIGN_TRIGGERS,
  'referral',
  'social',
  'renewal',
] as const;

/**
 * Triggers this build can offer. Mirrors CampaignTrigger::all().
 *
 * Build-time, from the Vite define — never a runtime flag. Used for the UI's
 * choices only; never for parsing.
 */
export const CAMPAIGN_TRIGGERS = __IS_PRO__
  ? ([...LITE_CAMPAIGN_TRIGGERS, ...PRO_CAMPAIGN_TRIGGERS] as const)
  : LITE_CAMPAIGN_TRIGGERS;

/**
 * Stored statuses. Mirrors Enums\Status::all().
 *
 * Merchant intent only. There is deliberately no 'scheduled' or 'ended' here —
 * whether a campaign is live is derived from status plus the date window,
 * evaluated against the event's timestamp rather than now().
 */
export const CAMPAIGN_STATUSES = ['active', 'paused', 'draft', 'archived'] as const;

/** Parses what the server sends — the full known vocabulary, in both builds. */
export const campaignTriggerSchema = z.enum(ALL_KNOWN_CAMPAIGN_TRIGGERS);
export const campaignStatusSchema = z.enum(CAMPAIGN_STATUSES);

export type CampaignTrigger = z.infer<typeof campaignTriggerSchema>;

/** A trigger this build can offer, as opposed to one it merely recognises. */
export type OfferableCampaignTrigger = (typeof CAMPAIGN_TRIGGERS)[number];
export type CampaignStatus = z.infer<typeof campaignStatusSchema>;

export const campaignSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** Generated server-side and immutable after create. */
  slug: z.string(),
  trigger: campaignTriggerSchema,
  status: campaignStatusSchema,
  priority: z.number().int(),
  /**
   * Shape depends on `trigger`; the server whitelists keys per trigger and
   * stamps schema_version. Kept loose here on purpose — validating the union
   * client-side would duplicate the PHP whitelist and drift from it.
   */
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

export const campaignListParamsSchema = z.object({
  page: z.number().int().optional(),
  per_page: z.number().int().optional(),
  trigger: campaignTriggerSchema.optional(),
  status: campaignStatusSchema.optional(),
  search: z.string().optional(),
});

export type CampaignListParams = z.infer<typeof campaignListParamsSchema>;
