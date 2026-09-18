import { z } from 'zod';

/**
 * Mirrors CustomerRestController::get_item_schema().
 *
 * THAT PHP METHOD IS THE SOURCE OF TRUTH. When a column is added there, add it
 * here; nothing generates one from the other.
 *
 * Field names are snake_case to match the PHP payload exactly. The pagination
 * envelope around collections is camelCase — see PaginatedResponse<T>.
 *
 * Every aggregate is server-owned and derived from the ledger; none of it is
 * writable, which is why there is no separate "create" or "update" shape.
 */
/**
 * Row counts behind the drawer's tab labels (`CustomerItemSchema::properties()`).
 * `activity`/`redemptions`/`referrals` are always 0 on the real path — those
 * tables don't exist yet; only the WP_DEBUG drawer fixture varies them.
 */
export const customerCountsSchema = z.object({
  ledger: z.number().int(),
  activity: z.number().int(),
  redemptions: z.number().int(),
  referrals: z.number().int(),
});

export const customerSchema = z.object({
  user_id: z.number().int(),
  /** From wp_users — carried here (not only the list row) so the drawer has an
   * identity to render on a deep link or a refresh. */
  name: z.string(),
  email: z.string(),
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
  /** Always null on the real path — no deleted_at column yet (docs/data-model.md). */
  deleted_at: z.string().nullable(),
  counts: customerCountsSchema,
});

export type Customer = z.infer<typeof customerSchema>;

/**
 * One row of `GET /customers` (the admin list) — NOT `Customer` above: this is
 * a JOIN projection carrying `name`/`email` from `wp_users` (the customer
 * entity has no place for them), and omits fields the list doesn't render
 * (`lifetime_redeemed`, `joined_at`, `updated_at`). Mirrors
 * `CustomerRestController::format_row()` field-for-field.
 */
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

/**
 * A raw `{key, count}` "which filter to drop" suggestion — see
 * `CustomerRowFilters::candidates_for_no_match()`. `key` is the wire/query
 * param name (`balance_range`, not `balanceRange`): the server never learns
 * how the admin app spells its own filter state — `customers-page.filter-suggestions.ts`
 * maps it to a `FilterKey`.
 */
export const noMatchSuggestionSchema = z.object({
  key: z.enum(['search', 'balance_range', 'expiring_soon', 'tier']),
  count: z.number().int(),
});

export type NoMatchSuggestion = z.infer<typeof noMatchSuggestionSchema>;

/**
 * The Customers list's filter/sort value unions — moved here (not declared
 * page-locally) because `customer.api.ts` needs them and `lib/` must not
 * import from `pages/`. `customers-page.filter-rows.ts` /
 * `customers-page.sort-rows.ts` re-export these; their labels/option arrays
 * are unaffected.
 */
export type CustomerBalanceRange = 'any' | 'negative' | '0-1000' | '1000-10000' | '10000-plus';
export type CustomerExpiringSoon = 'off' | '30' | '90' | 'none';
/** 'all' + the tiers the backend hands out. No tiers table exists yet — any non-'all' value matches zero rows (intentional, see CustomerRowFilters.php). */
export type CustomerTier = 'all' | 'Alpine' | 'Summit' | 'Explorer';
/** `tier`/`referrals` have no real backing data (see `CustomerRowSort.php`) — accepted for `defaultSortDirection()` but not offered as a clickable header. */
export type CustomerSortKey =
  'name' | 'tier' | 'balance' | 'pending' | 'lifetime' | 'last_activity' | 'expires' | 'referrals';
export type CustomerSortDirection = 'asc' | 'desc';

/** Request body for POST /customers/{id}/adjust. */
export const adjustPointsSchema = z.object({
  points: z.number().int(),
  note: z.string().min(1),
});

export type AdjustPointsInput = z.infer<typeof adjustPointsSchema>;
