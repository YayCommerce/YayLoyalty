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
  /** True for the row `customer.negative_balance.cause_ledger_id` names. */
  isCause: boolean;
};

/**
 * Maps a ledger entry to its table row. `subLine` names the campaign on an earn row, or quotes the customer
 * description on a manual adjustment: `LedgerEntry` has no reward name, original earn date or actor name,
 * so anything more would be invented.
 */
export function toLedgerRow(
  entry: LedgerEntry,
  campaignNames: Map<number, string>,
  causeLedgerId: number | null = null,
): LedgerRow {
  return {
    date: formatStoredDate(entry.created_at),
    description: describeLedgerEntry(entry),
    subLine: customerNoteSubLine(entry) ?? campaignSubLine(entry, campaignNames),
    points: formatSignedPoints(entry.points),
    balance: formatPoints(entry.balance_after),
    isCause: entry.id === causeLedgerId,
  };
}

function customerNoteSubLine(entry: LedgerEntry): string | null {
  return entry.customer_note
    ? sprintf(
        /* translators: %s: the description the customer sees for this adjustment. */
        __('Customer sees: %s', 'yayloyalty'),
        entry.customer_note,
      )
    : null;
}

function campaignSubLine(entry: LedgerEntry, campaignNames: Map<number, string>): string | null {
  if (entry.type !== 'earn' || entry.campaign_id === null) {
    return null;
  }

  const name = campaignNames.get(entry.campaign_id);

  return name
    ? sprintf(
        /* translators: %s: the campaign's name. */
        __('Campaign: %s', 'yayloyalty'),
        name,
      )
    : null;
}
