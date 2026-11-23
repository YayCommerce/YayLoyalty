import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/** Mirrors ActivityRestController::define_item_schema() and ActivityQueryArgs, the source of truth. */

/** Mirrors `ActivityEvent::ALL`, the source of truth. The nine event kinds, in display order. */
export const ACTIVITY_EVENT_TYPES = [
  'membership_started',
  'points_adjusted',
  'tier_changed',
  'expiry_reset',
  'reward_redeemed',
  'balance_email_sent',
  'expiring_soon_email_sent',
  'earning_limited',
  'account_deleted',
] as const;

export const ACTIVITY_ACTOR_KINDS = ['staff', 'automatic', 'customer'] as const;

/**
 * Mirrors `ActivityQueryArgs::GROUPS`, the source of truth. The `Show:` filter's groups over `ACTIVITY_EVENT_TYPES`.
 * Groups overlap on purpose: `expiring_soon_email_sent` is in both `expiry` and `emails`.
 */
export const ACTIVITY_GROUPS = ['all', 'admin', 'expiry', 'emails', 'tiers'] as const;

export const activityEventTypeSchema = z.enum(ACTIVITY_EVENT_TYPES);
export const activityActorKindSchema = z.enum(ACTIVITY_ACTOR_KINDS);
export const activityGroupSchema = z.enum(ACTIVITY_GROUPS);

export type ActivityEventType = z.infer<typeof activityEventTypeSchema>;
export type ActivityGroup = z.infer<typeof activityGroupSchema>;

export const activityEntrySchema = z.object({
  id: z.number().int(),
  occurred_at: z.string(),
  event_type: activityEventTypeSchema,
  /** Structured fields the copy module composes a sentence from. Never prose. */
  data: z.record(z.string(), z.unknown()),
  actor_kind: activityActorKindSchema,
  actor_name: z.string().nullable(),
  /** Display flag: this row quotes an internal admin note. Not access control. */
  staff_only: z.boolean(),
});

export type ActivityEntry = z.infer<typeof activityEntrySchema>;

export const activityEnvelopeSchema = paginatedSchema(activityEntrySchema);
