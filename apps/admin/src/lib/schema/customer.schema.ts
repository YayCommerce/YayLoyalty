import { z } from 'zod';

/**
 * Mirrors CustomerRestController::define_item_schema(), the source of
 * truth; nothing generates one from the other. Every aggregate is
 * server-owned, so there is no write shape.
 */
export const customerSchema = z.object({
  user_id: z.number().int(),
  /** Signed: a clawback after a refund can legitimately push this below zero. */
  balance: z.number().int(),
  pending_points: z.number().int(),
  /** Signed. Unaffected by redemptions, so spending never demotes a tier. */
  lifetime_earned: z.number().int(),
  lifetime_redeemed: z.number().int(),
  last_activity_at: z.string().nullable(),
  expires_at: z.string().nullable(),
  joined_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

export type Customer = z.infer<typeof customerSchema>;

/** One row of GET /customers: a JOIN projection with name/email, mirroring CustomerRestController::format_row(). */
export const customerListRowSchema = z.object({
  user_id: z.number().int(),
  name: z.string(),
  email: z.string(),
  balance: z.number().int(),
  pending_points: z.number().int(),
  lifetime_earned: z.number().int(),
  last_activity_at: z.string().nullable(),
  expires_at: z.string().nullable(),
});

export type CustomerListRow = z.infer<typeof customerListRowSchema>;

/** A "which filter to drop" suggestion; `key` is the wire param name, mapped in customers-page.filter-suggestions.ts. */
export const noMatchSuggestionSchema = z.object({
  key: z.enum(['search', 'balance_range', 'expiring_soon', 'tier']),
  count: z.number().int(),
});

export type NoMatchSuggestion = z.infer<typeof noMatchSuggestionSchema>;

/** Filter/sort value unions, here because customer.api.ts needs them and lib/ must not import pages/. */
export type CustomerBalanceRange = 'any' | 'negative' | '0-1000' | '1000-10000' | '10000-plus';
export type CustomerExpiringSoon = 'off' | '30' | '90' | 'none';
/** No tiers table yet: any non-'all' value matches zero rows server-side. */
export type CustomerTier = 'all' | 'Alpine' | 'Summit' | 'Explorer';
/** tier/referrals have no backing data yet; accepted but not offered as a clickable header. */
export type CustomerSortKey =
  'name' | 'tier' | 'balance' | 'pending' | 'lifetime' | 'last_activity' | 'expires' | 'referrals';
export type CustomerSortDirection = 'asc' | 'desc';

/** Request body for POST /customers/{id}/adjust. */
export const adjustPointsSchema = z.object({
  points: z.number().int(),
  note: z.string().min(1),
});

export type AdjustPointsInput = z.infer<typeof adjustPointsSchema>;
