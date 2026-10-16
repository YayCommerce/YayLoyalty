import * as v from 'valibot';

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
export const ACTIVITY_GROUPS = ['all', 'admin', 'expiry', 'emails'] as const;

export const activityEventTypeSchema = v.picklist(ACTIVITY_EVENT_TYPES);
export const activityActorKindSchema = v.picklist(ACTIVITY_ACTOR_KINDS);
export const activityGroupSchema = v.picklist(ACTIVITY_GROUPS);

export type ActivityEventType = v.InferOutput<typeof activityEventTypeSchema>;
export type ActivityGroup = v.InferOutput<typeof activityGroupSchema>;

export const activityEntrySchema = v.object({
  id: v.pipe(v.number(), v.integer()),
  occurred_at: v.string(),
  event_type: activityEventTypeSchema,
  /** Structured fields the copy module composes a sentence from. Never prose. */
  data: v.record(v.string(), v.unknown()),
  actor_kind: activityActorKindSchema,
  actor_name: v.nullable(v.string()),
  /** Display flag: this row quotes an internal admin note. Not access control. */
  staff_only: v.boolean(),
});

export type ActivityEntry = v.InferOutput<typeof activityEntrySchema>;

export const activityEnvelopeSchema = paginatedSchema(activityEntrySchema);
