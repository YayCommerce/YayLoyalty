import { formatWPDate } from '@/lib/helpers/format.helper';
import { formatCount } from '@/lib/helpers/points.helper';
import { discountText } from '../referral-discount';
import { CUSTOMER_REFERRALS_COPY } from './customer-referrals.copy';
import type { CustomerReferral, CustomerReferrals } from './customer-referrals.schema';

/** The "To {advocate}" cell: the points paid, the points waiting on the hold, or a dash. */
export function toAdvocateCell(referral: CustomerReferral): string {
  const points = `+${formatCount(referral.advocate_points)}`;

  if (referral.status === 'rewarded') return points;
  if (referral.status === 'on_hold') return CUSTOMER_REFERRALS_COPY.onHoldPoints(points);

  return '—';
}

/** The "To friend" cell: the discount, and whether the first order used it, it is still available, or it lapsed. */
export function toFriendCell(referral: CustomerReferral): string {
  const discount = discountText(referral.terms);

  if (referral.first_order) {
    return referral.first_order.used_code
      ? CUSTOMER_REFERRALS_COPY.discountUsed(discount)
      : CUSTOMER_REFERRALS_COPY.discountUnused(discount);
  }

  if (referral.status === 'pending' && referral.coupon_expires_at) {
    return CUSTOMER_REFERRALS_COPY.discountAvailable(discount, formatWPDate(referral.coupon_expires_at));
  }

  // A referral rejected at completion keeps no order, so whether the discount was used isn't known.
  return referral.status === 'expired' ? CUSTOMER_REFERRALS_COPY.discountExpired(discount) : '—';
}

/** The share of the customer's lifetime points that came from referrals, rounded; 0 with no lifetime points. */
export function pointsShare(counts: CustomerReferrals['counts'], lifetimeEarned: number): number {
  return lifetimeEarned > 0 ? Math.round((counts.points_earned / lifetimeEarned) * 100) : 0;
}
