import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import {
  creditedReferralsValue,
  LOOSE_ENDS_COUNTED_LABEL,
  LOOSE_ENDS_CREDITED_REFERRALS_LABEL,
  LOOSE_ENDS_TITLE,
  LOOSE_ENDS_VOID_COUPONS_LABEL,
  looseEndsLiabilitySentence,
  NO_LABEL,
  voidCouponsValue,
  YES_LABEL,
} from './customer-drawer.panels.copy';

/** 4a's "Loose ends this leaves" — the consequences of a deletion nobody
 *  thinks about at the time: void coupons, still-credited referrals, and
 *  whether the member total still counts them. */
export function CustomerDrawerLooseEnds({ customer }: { customer: Customer }) {
  // Only `loose_ends` needs checking here — it's the one field this component
  // reads, and TS can't see the parent's `deleted_at && loose_ends` guard
  // (prop types don't inherit call-site narrowing). `CustomerDrawerDeletedBanner`
  // checks both because it separately reads `deleted_at` for the date itself.
  if (!customer.loose_ends) return null;

  const { void_coupons, credited_referrals, counted_in_members } = customer.loose_ends;

  return (
    <div className="flex flex-col gap-3 rounded-md border p-3.5">
      <p className="text-sm font-medium">{LOOSE_ENDS_TITLE()}</p>
      <dl className="flex flex-col gap-2 text-sm">
        <div className="flex items-center justify-between gap-3">
          <dt className="text-muted-foreground">{LOOSE_ENDS_VOID_COUPONS_LABEL()}</dt>
          <dd className="tabular-nums">{voidCouponsValue(formatPoints(void_coupons.count), void_coupons.example)}</dd>
        </div>
        <div className="flex items-center justify-between gap-3">
          <dt className="text-muted-foreground">{LOOSE_ENDS_CREDITED_REFERRALS_LABEL()}</dt>
          <dd className="tabular-nums">{creditedReferralsValue(formatPoints(credited_referrals))}</dd>
        </div>
        <div className="flex items-center justify-between gap-3">
          <dt className="text-muted-foreground">{LOOSE_ENDS_COUNTED_LABEL()}</dt>
          <dd>{counted_in_members ? YES_LABEL() : NO_LABEL()}</dd>
        </div>
      </dl>
      <p className="text-muted-foreground text-xs">{looseEndsLiabilitySentence(formatPoints(customer.balance))}</p>
    </div>
  );
}
