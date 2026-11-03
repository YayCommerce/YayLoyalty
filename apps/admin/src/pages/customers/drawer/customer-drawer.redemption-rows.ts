import { formatStoredDate, formatWPCurrency } from '@/lib/helpers/format.helper';
import { formatSignedPoints } from '@/lib/helpers/ledger.helper';
import type { RedemptionEntry } from '@/lib/schema/redemption.schema';
import {
  REDEMPTION_STATUS_APPLIED,
  REDEMPTION_STATUS_CANCELLED,
  REDEMPTION_STATUS_REFUNDED,
} from './customer-drawer.copy';

export type RedemptionRow = {
  date: string;
  reward: string;
  value: string;
  points: string;
  order: string;
  status: string;
};

/** Maps a redemption entry to its table row. `points` arrives negative (points spent), so `formatSignedPoints()` shows it as is. */
export function toRedemptionRow(entry: RedemptionEntry): RedemptionRow {
  return {
    date: formatStoredDate(entry.created_at),
    reward: entry.reward_name,
    value: formatWPCurrency(entry.value),
    points: formatSignedPoints(entry.points),
    order: entry.order_id !== null ? `#${entry.order_id}` : '—',
    status: statusLabel(entry.status),
  };
}

function statusLabel(status: RedemptionEntry['status']): string {
  switch (status) {
    case 'applied':
      return REDEMPTION_STATUS_APPLIED();
    case 'refunded':
      return REDEMPTION_STATUS_REFUNDED();
    case 'cancelled':
      return REDEMPTION_STATUS_CANCELLED();
  }
}
