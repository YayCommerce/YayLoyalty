import { __, sprintf } from '@wordpress/i18n';

import { formatStoredDate } from '@/lib/helpers/format.helper';
import { describeLedgerEntry, formatSignedPoints } from '@/lib/helpers/ledger.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { LedgerEntry } from '@/lib/schema/ledger.schema';

export type LedgerRow = {
  date: string;
  description: string;
  subLine: string | null;
  points: string;
  balance: string;
  /** True for the one row `customer.negative_balance.cause_ledger_id` names
   *  (5a) — the row the negative-balance panel above is talking
   *  about. `false` on every other row. */
  isCause: boolean;
};

/**
 * Row → what the ledger table renders. Pure — no fetch, no React.
 *
 * `description` reuses `describeLedgerEntry()` rather than reimplementing
 * it (dashboard's recent-activity panel already does this). `subLine` is
 * this drawer's own addition, and is deliberately conservative: it only
 * ever states the campaign name, because `LedgerEntry` carries no reward
 * name, no "originally earned" date, and no actor display name — inventing
 * those for redeem/expire/adjust/correction rows would be fabricated prose.
 */
export function toLedgerRow(
  entry: LedgerEntry,
  campaignNames: Map<number, string>,
  causeLedgerId: number | null = null,
): LedgerRow {
  return {
    date: formatStoredDate(entry.created_at),
    description: describeLedgerEntry(entry),
    subLine: campaignSubLine(entry, campaignNames),
    points: formatSignedPoints(entry.points),
    balance: formatPoints(entry.balance_after),
    isCause: entry.id === causeLedgerId,
  };
}

function campaignSubLine(entry: LedgerEntry, campaignNames: Map<number, string>): string | null {
  if (entry.type !== 'earn' || entry.campaign_id === null) {
    return null;
  }

  const name = campaignNames.get(entry.campaign_id);

  return name ? sprintf(__('Campaign: %s', 'yayloyalty'), name) : null;
}
