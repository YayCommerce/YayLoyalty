import type { CustomerTier } from '@/lib/schema/customer.schema';

/**
 * TEMPORARY, UI-CHECK-ONLY SAMPLE DATA — not a real feature.
 *
 * No `tiers` table exists yet (see `TIER_FILTER_ENABLED` in
 * `customers-page.filter-rows.ts`), so the real `GET /customers` response
 * never carries a tier and `CustomerListRow` has no `tier` field to read.
 * This derives a plausible-looking one straight from `balance`, purely so
 * the Tier column has something to render while checking the UI.
 *
 * Edit `TIER_SAMPLE_THRESHOLDS` below to try a different distribution — the
 * one call site is `sampleTierForBalance()`, used only from
 * `CustomerTableRow.tsx`. Delete this file (and that call site) once VIP
 * Tiers ships a real per-customer tier from the server.
 */
const TIER_SAMPLE_THRESHOLDS: { min: number; tier: CustomerTier }[] = [
  { min: 10000, tier: 'Explorer' },
  { min: 1000, tier: 'Summit' },
  { min: 100, tier: 'Alpine' },
];

/** `null` (renders "—") for a negative balance — no earned status to show. */
export function sampleTierForBalance(balance: number): CustomerTier | null {
  return TIER_SAMPLE_THRESHOLDS.find((row) => balance >= row.min)?.tier ?? null;
}
