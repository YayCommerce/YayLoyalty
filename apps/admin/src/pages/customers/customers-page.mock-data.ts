import type { Customer } from '@/lib/schema/customer.schema';

/**
 * MOCK DATA ONLY. `MockCustomer` describes a row fetched from the TEMPORARY
 * demo endpoint (`customers-page.api.ts` → `GET /customers` →
 * `CustomerRestController::get_items()` / `CustomerDemoRows.php`), which
 * stands in for a real Customers list REST endpoint that does not exist yet
 * (`customer.queries.ts` only has single-customer / ledger / adjust hooks
 * today). The round trip is real; the six rows it returns are not.
 *
 * Deleted, along with `customers-page.api.ts`/`.queries.ts` and the PHP side,
 * the day the page reads a real list. Never import this outside
 * `pages/customers/`.
 */

/**
 * A demo row. Built from `Pick<Customer, …>` so the fields that already exist
 * on the real REST shape keep their real names/types — wiring real data later
 * is a rename, not a rewrite.
 *
 * `tier` and `referrals` are NOT on `customerSchema` yet: the DB columns exist
 * ahead of release (see docs/data-model.md) but `CustomerRestController::
 * get_item_schema()` does not expose them. They live here, demo-only, until it
 * does — do not add them to the shared schema for this build.
 */
export type MockCustomer = Pick<Customer, 'user_id' | 'balance' | 'pending_points' | 'lifetime_earned' | 'expires_at'> & {
  name: string;
  email: string;
  /** Pre-formatted display string ("2 days ago") — inventing a relative-time
   *  formatter for throwaway demo rows would be a second, divergent date path. */
  last_activity_label: string;
  /** Sort-only value backing `last_activity_label` — the label alone sorts
   *  wrong ("12 min ago" vs "3 hours ago" is not a string comparison). */
  last_activity_minutes_ago: number;
  tier: string;
  referrals: number;
};

/**
 * Balance/points display. Neither existing helper fits a balance column:
 * `formatSignedPoints` (ledger.helper.ts) prefixes `+` on positives, which is
 * correct for a ledger delta but wrong for a balance; `parseWPDecimal`
 * (format.helper.ts) applies WooCommerce currency decimals/separators, which
 * is for money, not points. A plain grouped integer is the honest choice.
 */
export function formatPoints(value: number): string {
  return value.toLocaleString();
}
