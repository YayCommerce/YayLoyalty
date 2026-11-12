import { describe, expect, it } from 'vitest';

import { customerListRowSchema, customerSchema } from '@/lib/schema/customer.schema';

/** The fields a `GET /customers` row and a `GET /customers/{id}` payload share. */
const ROW_WITHOUT_TIER = {
  user_id: 461,
  name: 'e2e780',
  email: 'e2e780@example.test',
  balance: 910,
  pending_points: 0,
  lifetime_earned: 910,
  last_activity_at: '2026-11-04 04:06:07',
  expires_at: null,
};

/** A `GET /customers` row as CustomerListFormatter::format_row() shapes it. */
const LIST_ROW = { ...ROW_WITHOUT_TIER, tier_id: 5 };

/** A `GET /customers/{id}` payload: no `tier_id`, plus the drawer-only fields. */
const DETAIL = {
  ...ROW_WITHOUT_TIER,
  lifetime_redeemed: 0,
  joined_at: '2026-11-04 04:04:42',
  updated_at: '2026-11-04 04:06:07',
  deleted_at: null,
  counts: { ledger: 3, activity: 4, redemptions: 0 },
  loose_ends: null,
  expiry: null,
  negative_balance: null,
};

describe('customer schemas', () => {
  // Regression: the list row's tier_id leaked into the detail schema, so every drawer failed to load.
  it('parses a detail payload that has no tier_id', () => {
    expect(customerSchema.safeParse(DETAIL).success).toBe(true);
  });

  it('still requires tier_id on a list row', () => {
    expect(customerListRowSchema.safeParse(LIST_ROW).success).toBe(true);
    expect(customerListRowSchema.safeParse(ROW_WITHOUT_TIER).success).toBe(false);
  });
});
