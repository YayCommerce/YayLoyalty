import { formatStoredDate, parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatSignedPoints } from '@/lib/helpers/ledger.helper';
import type { RedemptionEntry } from '@/lib/schema/redemption.schema';
import {
  REDEMPTION_STATUS_APPLIED,
  REDEMPTION_STATUS_ISSUED,
  REDEMPTION_STATUS_RETURNED,
} from './customer-drawer.copy';

export type RedemptionRow = {
  date: string;
  reward: string;
  value: string;
  points: string;
  order: string;
  status: string;
};

/** Maps a redemption entry to its table row. `points` arrives signed, so `formatSignedPoints()` only adds the `+`. */
export function toRedemptionRow(entry: RedemptionEntry): RedemptionRow {
  return {
    date: formatStoredDate(entry.created_at),
    reward: entry.reward_name,
    value: parseWPCurrency(entry.value),
    points: formatSignedPoints(entry.points),
    order: entry.order_id !== null ? `#${entry.order_id}` : '—',
    status: statusLabel(entry.status),
  };
}

function statusLabel(status: RedemptionEntry['status']): string {
  switch (status) {
    case 'applied':
      return REDEMPTION_STATUS_APPLIED();
    case 'returned':
      return REDEMPTION_STATUS_RETURNED();
    case 'issued':
      return REDEMPTION_STATUS_ISSUED();
  }
}
