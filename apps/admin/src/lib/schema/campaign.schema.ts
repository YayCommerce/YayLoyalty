import { z } from 'zod';

/**
 * Mirrors CampaignRestController::get_item_schema().
 *
 * THAT PHP METHOD IS THE SOURCE OF TRUTH.
 */

/** Matches Enums\CampaignTrigger::all() — the triggers this build can fire. */
export const CAMPAIGN_TRIGGERS = ['purchase', 'signup', 'review'] as const;

/**
 * Matches Enums\Status.
 *
 * There is deliberately no 'scheduled' or 'ended'. Status is merchant intent;
 * whether a campaign is live is derived from status plus the date window, and
 * evaluated against the event's timestamp rather than now().
 */
export const CAMPAIGN_STATUSES = ['active', 'paused', 'draft'] as const;

export const campaignTriggerSchema = z.enum(CAMPAIGN_TRIGGERS);
export const campaignStatusSchema = z.enum(CAMPAIGN_STATUSES);

export type CampaignTrigger = z.infer<typeof campaignTriggerSchema>;
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
