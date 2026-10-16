import * as v from 'valibot';

/**
 * Mirrors CustomerRestController::define_item_schema(), the source of truth.
 * Every aggregate is server-owned, so there is no write shape.
 */

/** Row counts behind the drawer's tab labels. `redemptions` is 0 until that table exists. */
export const customerCountsSchema = v.object({
  ledger: v.pipe(v.number(), v.integer()),
  activity: v.pipe(v.number(), v.integer()),
  redemptions: v.pipe(v.number(), v.integer()),
});

/**
 * What a deleted customer leaves behind; non-null exactly when `deleted_at` is.
 * `void_coupons` and `counted_in_members` are null until their features exist.
 * `void_coupons.example` is one illustrative label ("$5 off"), not a list.
 */
export const looseEndsSchema = v.object({
  void_coupons: v.nullable(
    v.object({
      count: v.pipe(v.number(), v.integer()),
      example: v.nullable(v.string()),
    }),
  ),
  counted_in_members: v.nullable(v.boolean()),
  kept_ledger_entries: v.pipe(v.number(), v.integer()),
});

export type LooseEnds = v.InferOutput<typeof looseEndsSchema>;

/**
 * The expiring-batch summary. Always null until the `lots` table exists.
 * `batches: null` means the inactivity model, which has no batches at all, not zero of them.
 * It carries figures only: percentages and money are derived client-side.
 */
export const drawerExpiryBatchSchema = v.object({
  earned_from: v.string(),
  earned_to: v.string(),
  points: v.pipe(v.number(), v.integer()),
  expires_at: v.string(),
});

export const drawerExpirySchema = v.object({
  expiring_points: v.pipe(v.number(), v.integer()),
  expires_at: v.string(),
  safe_points: v.pipe(v.number(), v.integer()),
  reminders: v.object({
    sent_at: v.nullable(v.string()),
    next_due_at: v.nullable(v.string()),
    window_days: v.pipe(v.number(), v.integer()),
  }),
  batches: v.nullable(v.array(drawerExpiryBatchSchema)),
});

export type DrawerExpiry = v.InferOutput<typeof drawerExpirySchema>;

/**
 * How the balance went negative. Always null until the server walks the ledger backwards.
 * `shape` is an enum, so an unknown value fails at parse time instead of rendering a half-filled story.
 * `'other'` means an adjustment or an expiry, not a refund, caused the deficit, so the panel explains it generically.
 */
export const drawerNegativeBalanceShapeSchema = v.picklist(['refund_reversal', 'other']);
export type DrawerNegativeBalanceShape = v.InferOutput<typeof drawerNegativeBalanceShapeSchema>;

const drawerNegativeBalanceLegSchema = v.object({
  order_id: v.pipe(v.number(), v.integer()),
  points: v.pipe(v.number(), v.integer()),
  occurred_at: v.string(),
});

export const drawerNegativeBalanceSchema = v.object({
  shape: drawerNegativeBalanceShapeSchema,
  earned: v.nullable(drawerNegativeBalanceLegSchema),
  spent: v.nullable(
    v.object({
      order_id: v.pipe(v.number(), v.integer()),
      points: v.pipe(v.number(), v.integer()),
      /** Discount value in store currency, e.g. "10.00": a string like other money values, never a formatted price. */
      value: v.string(),
    }),
  ),
  reversed: v.nullable(drawerNegativeBalanceLegSchema),
  /** The ledger row this story is about, which the ledger tab marks. */
  cause_ledger_id: v.nullable(v.pipe(v.number(), v.integer())),
  deficit: v.pipe(v.number(), v.integer()),
});

export type DrawerNegativeBalance = v.InferOutput<typeof drawerNegativeBalanceSchema>;

/** Mirrors CustomerListFormatter::format_row(), the source of truth. One row of GET /customers, a JOIN projection with name/email. */
export const customerListRowSchema = v.object({
  user_id: v.pipe(v.number(), v.integer()),
  name: v.string(),
  email: v.string(),
  /** Signed: a clawback after a refund can legitimately push this below zero. */
  balance: v.pipe(v.number(), v.integer()),
  pending_points: v.pipe(v.number(), v.integer()),
  /** Signed. Unaffected by redemptions, so spending never demotes a tier. */
  lifetime_earned: v.pipe(v.number(), v.integer()),
  last_activity_at: v.nullable(v.string()),
  expires_at: v.nullable(v.string()),
});

export type CustomerListRow = v.InferOutput<typeof customerListRowSchema>;

/**
 * GET /customers/{id}: the list row plus the drawer-only aggregates and panels.
 * It carries name and email so a deep link or a refresh has an identity to render.
 */
export const customerSchema = v.object({
  .../*@valibot-migrate we can't detect if customerListRowSchema has a `pipe` operator, if it does you might need to migrate this by hand otherwise it will loose it's pipeline*/
  customerListRowSchema.entries,

  lifetime_redeemed: v.pipe(v.number(), v.integer()),
  joined_at: v.nullable(v.string()),
  updated_at: v.nullable(v.string()),

  /** Derived from the latest account_deleted activity row while the WP user is gone. Never a column. */
  deleted_at: v.nullable(v.string()),

  counts: customerCountsSchema,
  loose_ends: v.nullable(looseEndsSchema),
  expiry: v.nullable(drawerExpirySchema),
  negative_balance: v.nullable(drawerNegativeBalanceSchema),
});

export type Customer = v.InferOutput<typeof customerSchema>;

/**
 * Mirrors the keys of CustomerRowFilters::candidates_for_no_match(), the source of truth.
 * A "which filter to drop" suggestion, mapped in customers-page.filter-suggestions.ts.
 */
export const noMatchSuggestionSchema = v.object({
  key: v.picklist(['search', 'balance_range', 'expiring_soon']),
  count: v.pipe(v.number(), v.integer()),
});

export type NoMatchSuggestion = v.InferOutput<typeof noMatchSuggestionSchema>;

/** Filter/sort value unions, here because customer.api.ts needs them and lib/ must not import pages/. */
export type CustomerBalanceRange = 'any' | 'negative' | '0-1000' | '1000-10000' | '10000-plus';
export type CustomerExpiringSoon = 'off' | '30' | '90' | 'none';
export type CustomerSortKey = 'name' | 'balance' | 'pending' | 'lifetime' | 'last_activity' | 'expires';
export type CustomerSortDirection = 'asc' | 'desc';

/** Request body for POST /customers/{id}/adjust — `adjustLifetime` and `customerNote` map to the wire's `adjust_lifetime` and `customer_note` in customer.api.ts; `notify` goes as is. */
export const adjustPointsSchema = v.object({
  points: v.pipe(v.number(), v.integer()),
  note: v.pipe(v.string(), v.minLength(1)),
  adjustLifetime: v.boolean(),
  notify: v.boolean(),
  customerNote: v.optional(v.string()),
});

export type AdjustPointsInput = v.InferOutput<typeof adjustPointsSchema>;

/** Mirrors CustomerAdjustPreviewRestController::preview(), the source of truth. `count` is null unless filters were sent. */
export const adjustPointsPreviewSchema = v.object({
  count: v.nullable(v.pipe(v.number(), v.integer())),
  liability_points: v.pipe(v.number(), v.integer()),
});

export type AdjustPointsPreview = v.InferOutput<typeof adjustPointsPreviewSchema>;

/**
 * `filters` only for "select all matching" (a ticked list knows its own
 * count). Typed structurally, not as `RowFilters`, so this lib-level module
 * never imports from `pages/customers/`.
 */
export type AdjustPointsPreviewInput = {
  filters?: { search: string; balanceRange: string; expiringSoon: string };
};
