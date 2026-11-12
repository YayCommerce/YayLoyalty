import { describe, expect, it } from 'vitest';

import { dashboardActivityRowSchema } from '@/lib/schema/dashboard.schema';
import { ledgerEntrySchema } from '@/lib/schema/ledger.schema';

/** A ledger row as LedgerEntry::to_array() shapes it: no `tier_name`, which only the ledger list routes add. */
const ROW = {
  id: 835,
  user_id: 461,
  points: 100,
  balance_after: 910,
  type: 'earn',
  source: 'tier_bonus',
  reference_type: 'tier',
  reference_id: 5,
  campaign_id: null,
  redemption_id: null,
  order_id: null,
  note: null,
  customer_note: null,
  actor_id: null,
  created_at: '2026-11-04 04:06:07',
};

// Regression: tier_name was required, so GET /dashboard and POST /customers/{id}/adjust failed to parse.
describe('ledger rows without tier_name', () => {
  it('parse as an adjust response, with tier_name null', () => {
    expect(ledgerEntrySchema.parse(ROW).tier_name).toBeNull();
  });

  it('parse as a dashboard activity row', () => {
    expect(dashboardActivityRowSchema.safeParse({ ...ROW, name: 'e2e780' }).success).toBe(true);
  });

  it('keep tier_name when the ledger list sends it', () => {
    expect(ledgerEntrySchema.parse({ ...ROW, tier_name: 'Silver' }).tier_name).toBe('Silver');
  });
});
