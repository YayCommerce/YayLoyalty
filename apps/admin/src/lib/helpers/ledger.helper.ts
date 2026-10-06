import { __, sprintf } from '@wordpress/i18n';

import type { LedgerEntry, LedgerType } from '@/lib/schema/ledger.schema';

/** A human description for a ledger row. Built here, not stored, so system rows stay translatable. */
export function describeLedgerEntry(entry: LedgerEntry): string {
  if (entry.type === 'adjust') {
    return entry.note?.trim() || __('Manual adjustment', 'yayloyalty');
  }

  if (entry.type === 'correction') {
    return entry.note?.trim() || __('Balance correction', 'yayloyalty');
  }

  switch (entry.type) {
    case 'earn':
      return describeEarn(entry);

    case 'redeem':
      return __('Redeemed for a reward', 'yayloyalty');

    case 'refund_earn':
      return entry.order_id
        ? sprintf(
            /* translators: %d: the order number. */
            __('Points reclaimed after a refund on order #%d', 'yayloyalty'),
            entry.order_id,
          )
        : __('Points reclaimed after a refund', 'yayloyalty');

    case 'refund_redeem':
      return __('Points returned from a cancelled redemption', 'yayloyalty');

    case 'expire':
      return __('Points expired', 'yayloyalty');

    default:
      return __('Balance change', 'yayloyalty');
  }
}

function describeEarn(entry: LedgerEntry): string {
  switch (entry.source) {
    case 'order':
      return entry.order_id
        ? sprintf(
            /* translators: %d: the order number. */
            __('Earned on order #%d', 'yayloyalty'),
            entry.order_id,
          )
        : __('Earned on an order', 'yayloyalty');

    case 'signup':
      return __('Earned for signing up', 'yayloyalty');

    case 'review':
      return __('Earned for writing a review', 'yayloyalty');

    case 'referral':
      return __('Earned from a referral', 'yayloyalty');

    case 'birthday':
      return __('Birthday bonus', 'yayloyalty');

    case 'import':
      return __('Imported balance', 'yayloyalty');

    default:
      return __('Points earned', 'yayloyalty');
  }
}

const LEDGER_TYPE_LABELS: Record<LedgerType, () => string> = {
  earn: () => __('Earned', 'yayloyalty'),
  redeem: () => __('Redeemed', 'yayloyalty'),
  adjust: () => __('Adjusted', 'yayloyalty'),
  expire: () => __('Expired', 'yayloyalty'),
  refund_earn: () => __('Earn refunded', 'yayloyalty'),
  refund_redeem: () => __('Redemption refunded', 'yayloyalty'),
  correction: () => __('Correction', 'yayloyalty'),
};

/** The display name of a ledger type: the Ledger tab's filter and the ledger CSV's Type column. */
export function ledgerTypeLabel(type: LedgerType): string {
  return LEDGER_TYPE_LABELS[type]();
}

/** Signed display, so a clawback reads as -30 rather than 30. */
export function formatSignedPoints(points: number): string {
  return points > 0 ? `+${points}` : String(points);
}
