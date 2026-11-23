import type { VariantProps } from 'class-variance-authority';

import { decimalsFor, formatStoreNumber, formatWPCurrency, formatWPDate, storeCurrency } from '@/lib/helpers/format.helper';
import { shortPoints } from '@/lib/helpers/points.helper';
import type { badgeVariants } from '@/components/ui/badge';
import { REFERRAL_STATUS_LABEL, REFERRALS_COPY, REJECT_REASON_LABEL } from '../referrals.copy';
import type { ReferralListItem, ReferralStatus } from '../referrals.schema';

const STATUS_VARIANT: Record<ReferralStatus, VariantProps<typeof badgeVariants>['variant']> = {
  rewarded: 'success-soft',
  on_hold: 'warning-soft',
  pending: 'primary-soft',
  rejected: 'destructive-soft',
  expired: 'dimmed',
  reversed: 'destructive-soft',
};

/** `p•••@example.com`: the first character and the domain. */
export function maskEmail(email: string): string {
  const at = email.indexOf('@');

  return at < 1 ? email : `${email[0]}•••${email.slice(at)}`;
}

/** The muted line under a status badge, or '' when the status needs none. */
export function statusReason(item: ReferralListItem): string {
  switch (item.status) {
    case 'on_hold':
      return item.hold_until ? REFERRALS_COPY.releases(formatWPDate(item.hold_until)) : '';
    case 'pending':
      return REFERRALS_COPY.noOrderYet();
    case 'rejected':
      return item.reject_reason ? (REJECT_REASON_LABEL[item.reject_reason]?.() ?? item.reject_reason) : '';
    case 'expired':
      return REFERRALS_COPY.noOrderBeforeExpiry();
    case 'reversed':
      return REFERRALS_COPY.orderRefunded();
    default:
      return '';
  }
}

/** The order total in the store's format, or with its currency code when the order was charged in another currency. */
function orderValue(order: NonNullable<ReferralListItem['first_order']>): string {
  return order.currency === storeCurrency()
    ? formatWPCurrency(order.total)
    : `${formatStoreNumber(parseFloat(order.total), decimalsFor(order.currency))} ${order.currency}`;
}

/** What a list row shows, so the Referrals and Approvals tables render it the same way. */
export function toReferralRow(item: ReferralListItem) {
  const paid = item.status === 'rewarded' || item.status === 'on_hold';

  return {
    status: REFERRAL_STATUS_LABEL[item.status](),
    variant: STATUS_VARIANT[item.status],
    reason: statusReason(item),
    friend: maskEmail(item.friend.email),
    order: item.first_order ? `#${item.first_order.number}` : '—',
    orderUrl: item.first_order?.edit_url ?? '',
    value: item.first_order ? orderValue(item.first_order) : '—',
    reward: paid ? shortPoints(item.advocate_points) : '—',
    date: formatWPDate(item.created_at),
  };
}
