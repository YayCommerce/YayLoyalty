import { sprintf, __ } from '@wordpress/i18n';

import { parseWPDate, utcToDate } from '@/lib/helpers/format.helper';
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
   *  (5a, F09 Wave B) — the row the negative-balance panel above is talking
   *  about. `false` on every other row, on every other scenario. */
  isCause: boolean;
};

/**
 * Row → what the ledger table renders. Pure — no fetch, no React.
 *
 * `description` reuses `describeLedgerEntry()` rather than reimplementing
 * it (dashboard's recent-activity panel already does this). `subLine` is
 * this drawer's own addition, and is deliberately conservative: it only
 * ever states the campaign name, because that is the only extra fact this
 * wave's data actually carries. `LedgerEntry` has no reward name, no
 * "originally earned" date, and no actor display name — inventing those
 * for redeem/expire/adjust rows would be exactly the fabricated-prose the
 * fixture phase went out of its way to avoid (see phase 02's docblocks).
 */
export function toLedgerRow(
  entry: LedgerEntry,
  campaignNames: Map<number, string>,
  causeLedgerId: number | null = null,
): LedgerRow {
  return {
    date: parseWPDate(utcToDate(entry.created_at)),
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
