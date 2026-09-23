import { z } from 'zod';

/**
 * Mirrors CustomerRestController::define_item_schema(), the source of
 * truth; nothing generates one from the other. Every aggregate is
 * server-owned, so there is no write shape.
 */
/**
 * Row counts behind the drawer's tab labels (`CustomerItemSchema::properties()`).
 * `ledger`/`activity` are real; `redemptions`/`referrals` are always 0 —
 * those tables don't exist yet.
 */
export const customerCountsSchema = z.object({
  ledger: z.number().int(),
  activity: z.number().int(),
  redemptions: z.number().int(),
  referrals: z.number().int(),
});

/**
 * 4a's "Loose ends this leaves" facts — non-null exactly when
 * `deleted_at` is non-null. `kept_ledger_entries` is always real;
 * `void_coupons`/`credited_referrals`/`counted_in_members` are always
 * `null` — no redemptions/referrals/member-count feature exists — and
 * render as "Not tracked yet" rather than being hidden.
 * `void_coupons.example` is a single illustrative label ("$5 off"), not a list.
 */
export const looseEndsSchema = z.object({
  void_coupons: z
    .object({
      count: z.number().int(),
      example: z.string().nullable(),
    })
    .nullable(),
  credited_referrals: z.number().int().nullable(),
  counted_in_members: z.boolean().nullable(),
  kept_ledger_entries: z.number().int(),
});

export type LooseEnds = z.infer<typeof looseEndsSchema>;

/**
 * 4b's expiring-batch summary — always `null` today, since it
 * needs `lots`, which doesn't exist yet (docs/data-model.md). `batches` is
 * separately nullable inside: `null` means the Free inactivity model (no
 * batches exist at all, not "zero"). Percentages and money are derived
 * client-side — this carries figures only.
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
 * 5a's structured story — always `null` today, since it needs a backwards
 * ledger walk the server doesn't do yet. `shape` is a
 * Zod enum, not a bare string: an unknown
 * discriminator fails loudly at parse time rather than the panel rendering
 * a blank or half-filled narrative. `'other'` means the three-step story
 * cannot be assembled (a manual adjustment or an expiry clawback pushed the
 * balance below zero, not a refund) — the panel falls back to a generic
 * explanation, never guessing at legs that are `null`.
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
      /** Discount value in store currency, e.g. "10.00" — a string, same as
       *  `Reward`/redemption values elsewhere, never a pre-formatted price. */
      value: z.string(),
    })
    .nullable(),
  reversed: drawerNegativeBalanceLegSchema.nullable(),
  /** The real ledger row this story is about — `LedgerTabRows.tsx` marks it. */
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

/** GET /customers/{id}: the list row (name/email included so a deep link or a
 * refresh has an identity to render) plus the drawer-only aggregates and panels. */
export const customerSchema = customerListRowSchema.extend({
  lifetime_redeemed: z.number().int(),
  joined_at: z.string().nullable(),
  updated_at: z.string().nullable(),
  /** Derived from the latest account_deleted activity row, gated on the WP user currently being gone — never a column. */
  deleted_at: z.string().nullable(),
  counts: customerCountsSchema,
  loose_ends: looseEndsSchema.nullable(),
  expiry: drawerExpirySchema.nullable(),
  negative_balance: drawerNegativeBalanceSchema.nullable(),
  /** The expiry/negative-balance panels' only currency rate. */
  points_per_currency_unit: z.number(),
});

export type Customer = z.infer<typeof customerSchema>;

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

/** Request body for POST /customers/{id}/adjust — `adjustLifetime` maps to the wire's `adjust_lifetime` in customer.api.ts. */
export const adjustPointsSchema = z.object({
  points: z.number().int(),
  note: z.string().min(1),
  adjustLifetime: z.boolean(),
});

export type AdjustPointsInput = z.infer<typeof adjustPointsSchema>;

/** Mirrors CustomerAdjustPreviewRestController::preview(). `count` is null unless filters were sent. */
export const adjustPointsPreviewSchema = z.object({
  count: z.number().int().nullable(),
  liability_points: z.number().int(),
  points_per_currency_unit: z.number(),
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
