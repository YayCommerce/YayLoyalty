import { z } from 'zod';

/**
 * Mirrors CustomerRestController::define_item_schema(), the source of truth.
 * Every aggregate is server-owned, so there is no write shape.
 */

/** Row counts behind the drawer's tab labels. `redemptions` is 0 until that table exists. */
export const customerCountsSchema = z.object({
  ledger: z.number().int(),
  activity: z.number().int(),
  redemptions: z.number().int(),
});

/**
 * What a deleted customer leaves behind; non-null exactly when `deleted_at` is.
 * `void_coupons` and `counted_in_members` are null until their features exist.
 * `void_coupons.example` is one illustrative label ("$5 off"), not a list.
 */
export const looseEndsSchema = z.object({
  void_coupons: z
    .object({
      count: z.number().int(),
      example: z.string().nullable(),
    })
    .nullable(),
  counted_in_members: z.boolean().nullable(),
  kept_ledger_entries: z.number().int(),
});

export type LooseEnds = z.infer<typeof looseEndsSchema>;

/**
 * The expiring-batch summary. Always null until the `lots` table exists.
 * `batches: null` means the inactivity model, which has no batches at all, not zero of them.
 * It carries figures only: percentages and money are derived client-side.
 */
export const drawerExpiryBatchSchema = z.object({
  earned_from: z.string(),
  earned_to: z.string(),
  points: z.number().int(),
  expires_at: z.string(),
});

export const drawerExpirySchema = z.object({
  expiring_points: z.number().int(),
  expires_at: z.string(),
  safe_points: z.number().int(),
  reminders: z.object({
    sent_at: z.string().nullable(),
    next_due_at: z.string().nullable(),
    window_days: z.number().int(),
  }),
  batches: z.array(drawerExpiryBatchSchema).nullable(),
});

export type DrawerExpiry = z.infer<typeof drawerExpirySchema>;

/**
 * How the balance went negative. Always null until the server walks the ledger backwards.
 * `shape` is an enum, so an unknown value fails at parse time instead of rendering a half-filled story.
 * `'other'` means an adjustment or an expiry, not a refund, caused the deficit, so the panel explains it generically.
 */
export const drawerNegativeBalanceShapeSchema = z.enum(['refund_reversal', 'other']);
export type DrawerNegativeBalanceShape = z.infer<typeof drawerNegativeBalanceShapeSchema>;

const drawerNegativeBalanceLegSchema = z.object({
  order_id: z.number().int(),
  points: z.number().int(),
  occurred_at: z.string(),
});

export const drawerNegativeBalanceSchema = z.object({
  shape: drawerNegativeBalanceShapeSchema,
  earned: drawerNegativeBalanceLegSchema.nullable(),
  spent: z
    .object({
      order_id: z.number().int(),
      points: z.number().int(),
      /** Discount value in store currency, e.g. "10.00": a string like other money values, never a formatted price. */
      value: z.string(),
    })
    .nullable(),
  reversed: drawerNegativeBalanceLegSchema.nullable(),
  /** The ledger row this story is about, which the ledger tab marks. */
  cause_ledger_id: z.number().int().nullable(),
  deficit: z.number().int(),
});

export type DrawerNegativeBalance = z.infer<typeof drawerNegativeBalanceSchema>;

/** One row of GET /customers: a JOIN projection with name/email, mirroring CustomerListFormatter::format_row(). */
export const customerListRowSchema = z.object({
  user_id: z.number().int(),
  name: z.string(),
  email: z.string(),
  /** Signed: a clawback after a refund can legitimately push this below zero. */
  balance: z.number().int(),
  pending_points: z.number().int(),
  /** Signed. Unaffected by redemptions, so spending never demotes a tier. */
  lifetime_earned: z.number().int(),
  last_activity_at: z.string().nullable(),
  expires_at: z.string().nullable(),
});

export type CustomerListRow = z.infer<typeof customerListRowSchema>;

/**
 * GET /customers/{id}: the list row plus the drawer-only aggregates and panels.
 * It carries name and email so a deep link or a refresh has an identity to render.
 */
export const customerSchema = customerListRowSchema.extend({
  lifetime_redeemed: z.number().int(),
  joined_at: z.string().nullable(),
  updated_at: z.string().nullable(),
  /** Derived from the latest account_deleted activity row while the WP user is gone. Never a column. */
  deleted_at: z.string().nullable(),
  counts: customerCountsSchema,
  loose_ends: looseEndsSchema.nullable(),
  expiry: drawerExpirySchema.nullable(),
  negative_balance: drawerNegativeBalanceSchema.nullable(),
});

export type Customer = z.infer<typeof customerSchema>;

/**
 * A "which filter to drop" suggestion: keys of CustomerRowFilters::candidates_for_no_match().
 * Mapped in customers-page.filter-suggestions.ts.
 */
export const noMatchSuggestionSchema = z.object({
  key: z.enum(['search', 'balance_range', 'expiring_soon']),
  count: z.number().int(),
});

export type NoMatchSuggestion = z.infer<typeof noMatchSuggestionSchema>;

/** Filter/sort value unions, here because customer.api.ts needs them and lib/ must not import pages/. */
export type CustomerBalanceRange = 'any' | 'negative' | '0-1000' | '1000-10000' | '10000-plus';
export type CustomerExpiringSoon = 'off' | '30' | '90' | 'none';
export type CustomerSortKey = 'name' | 'balance' | 'pending' | 'lifetime' | 'last_activity' | 'expires';
export type CustomerSortDirection = 'asc' | 'desc';

/** Request body for POST /customers/{id}/adjust — `adjustLifetime` and `customerNote` map to the wire's `adjust_lifetime` and `customer_note` in customer.api.ts; `notify` goes as is. */
export const adjustPointsSchema = z.object({
  points: z.number().int(),
  note: z.string().min(1),
  adjustLifetime: z.boolean(),
  notify: z.boolean(),
  customerNote: z.string().optional(),
});

export type AdjustPointsInput = z.infer<typeof adjustPointsSchema>;

/** Mirrors CustomerAdjustPreviewRestController::preview(). `count` is null unless filters were sent. */
export const adjustPointsPreviewSchema = z.object({
  count: z.number().int().nullable(),
  liability_points: z.number().int(),
});

export type AdjustPointsPreview = z.infer<typeof adjustPointsPreviewSchema>;

/**
 * `filters` only for "select all matching" (a ticked list knows its own
 * count). Typed structurally, not as `RowFilters`, so this lib-level module
 * never imports from `pages/customers/`.
 */
export type AdjustPointsPreviewInput = {
  filters?: { search: string; balanceRange: string; expiringSoon: string };
};
