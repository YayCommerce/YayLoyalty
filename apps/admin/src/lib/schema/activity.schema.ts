import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors `ActivityRestController::define_item_schema()` and
 * `ActivityQueryArgs`. THAT PHP CODE IS THE SOURCE OF TRUTH — copies
 * `ledger.schema.ts`'s own docblock convention.
 *
 * The real table backs three of the eight kinds today (`account_created`,
 * `points_adjusted`, `account_deleted`); the other five have no source
 * feature yet, so a customer whose only history would be one of them
 * legitimately gets zero rows.
 */

/** The eight event kinds, in artboard order. Matches `ActivityEvent::ALL`. */
export const ACTIVITY_EVENT_TYPES = [
  'account_created',
  'points_adjusted',
  'tier_changed',
  'expiry_reset',
  'reward_redeemed',
  'balance_email_sent',
  'expiring_soon_email_sent',
  'account_deleted',
] as const;

export const ACTIVITY_ACTOR_KINDS = ['staff', 'automatic', 'customer'] as const;

/**
 * Filter vocabulary for the `Show:` control — a GROUPING over
 * `ACTIVITY_EVENT_TYPES`, not the same list. Matches `ActivityQueryArgs::GROUPS`.
 * Groups overlap on purpose (`expiring_soon_email_sent` is in both `expiry`
 * and `emails`) — it is a lens, not a partition. Do not "fix" the overlap.
 */
export const ACTIVITY_GROUPS = ['all', 'admin', 'tier', 'expiry', 'emails'] as const;

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
