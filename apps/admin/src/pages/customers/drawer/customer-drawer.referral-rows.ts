import { parseWPDate, parseWPCurrency, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { ReferralEntry } from '@/lib/schema/referral.schema';
import {
  COUPON_ISSUED_LABEL,
  COUPON_UNUSED_LABEL,
  COUPON_USED_LABEL,
  pendingWaitingOnOrder,
  REFERRAL_LAPSED_NOTE,
  REFERRAL_STATUS_COMPLETED,
  REFERRAL_STATUS_LAPSED,
  REFERRAL_STATUS_PENDING,
} from './customer-drawer.referrals.copy';

export type ReferralRow = {
  referredName: string;
  referredEmail: string;
  signedUpAt: string;
  firstOrder: string;
  referrerPoints: string;
  friendCoupon: string;
  status: string;
  /** Only set for `pending`/`lapsed` — the missing-condition note 3f requires beside the status. */
  statusNote: string | null;
};

/**
 * Row → what the referrals table renders. Pure — no fetch, no React.
 * `friendCoupon` composes the label with its used/unused/issued state in one
 * string (3f shows both sides of the reward, coupon included).
 */
export function toReferralRow(entry: ReferralEntry): ReferralRow {
  return {
    referredName: entry.referred_name,
    referredEmail: entry.referred_email,
    signedUpAt: parseWPDate(utcToDate(entry.signed_up_at)),
    firstOrder: formatFirstOrder(entry.first_order_id, entry.first_order_total),
    referrerPoints: entry.referrer_points > 0 ? `+${formatPoints(entry.referrer_points)}` : formatPoints(entry.referrer_points),
    friendCoupon: formatFriendCoupon(entry.friend_coupon, entry.status),
    status: statusLabel(entry.status),
    statusNote: statusNote(entry.status, entry.window_closes_at),
  };
}

function formatFirstOrder(orderId: number | null, total: string | null): string {
  if (orderId === null || total === null) return '—';

  return `#${orderId} · ${parseWPCurrency(total)}`;
}

/**
 * `pending`'s coupon reads "issued" rather than "unused" — the friend has
 * been sent the coupon, but the referral itself hasn't qualified yet, so
 * "unused" would misleadingly suggest the referral is otherwise done.
 */
function formatFriendCoupon(coupon: ReferralEntry['friend_coupon'], status: ReferralEntry['status']): string {
  const state = status === 'pending' ? COUPON_ISSUED_LABEL() : coupon.used ? COUPON_USED_LABEL() : COUPON_UNUSED_LABEL();

  return `${coupon.label} · ${state}`;
}

function statusLabel(status: ReferralEntry['status']): string {
  switch (status) {
    case 'completed':
      return REFERRAL_STATUS_COMPLETED();
    case 'pending':
      return REFERRAL_STATUS_PENDING();
    case 'lapsed':
      return REFERRAL_STATUS_LAPSED();
  }
}

function statusNote(status: ReferralEntry['status'], windowClosesAt: string | null): string | null {
  if (status === 'pending' && windowClosesAt) {
    return pendingWaitingOnOrder(parseWPDate(utcToDate(windowClosesAt)));
  }

  if (status === 'lapsed') {
    return REFERRAL_LAPSED_NOTE();
  }

  return null;
}
