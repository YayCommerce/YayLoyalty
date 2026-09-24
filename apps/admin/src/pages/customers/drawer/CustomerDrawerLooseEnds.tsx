import { formatPoints } from '@/lib/helpers/points.helper';
import type { LooseEnds } from '@/lib/schema/customer.schema';
import {
  countedInMembersValue,
  creditedReferralsValue,
  LOOSE_ENDS_COUNTED_LABEL,
  LOOSE_ENDS_CREDITED_REFERRALS_LABEL,
  LOOSE_ENDS_TITLE,
  LOOSE_ENDS_VOID_COUPONS_LABEL,
  looseEndsLiabilitySentence,
  voidCouponsValue,
} from './customer-drawer.panels.copy';

/** 4a's "Loose ends this leaves" — the consequences of a deletion nobody
 *  thinks about at the time: void coupons, still-credited referrals, and
 *  whether the member total still counts them. All three render even when
 *  `null` (no redemptions/referrals/member-count feature exists yet) — the
 *  composers in `customer-drawer.panels.copy.ts` turn that into "Not tracked
 *  yet" rather than a fabricated value. Only `kept_ledger_entries` is always
 *  real. */
export function CustomerDrawerLooseEnds({ looseEnds, balance }: { looseEnds: LooseEnds; balance: number }) {
  const { void_coupons, credited_referrals, counted_in_members } = looseEnds;

  return (
    <div className="flex flex-col gap-3 rounded-md border p-3.5">
      <p className="text-sm font-medium">{LOOSE_ENDS_TITLE()}</p>
      <dl className="flex flex-col gap-2 text-sm">
        <div className="flex items-center justify-between gap-3">
          <dt className="text-muted-foreground">{LOOSE_ENDS_VOID_COUPONS_LABEL()}</dt>
          <dd className="tabular-nums">{voidCouponsValue(void_coupons)}</dd>
        </div>
        <div className="flex items-center justify-between gap-3">
          <dt className="text-muted-foreground">{LOOSE_ENDS_CREDITED_REFERRALS_LABEL()}</dt>
          <dd className="tabular-nums">{creditedReferralsValue(credited_referrals)}</dd>
        </div>
        <div className="flex items-center justify-between gap-3">
          <dt className="text-muted-foreground">{LOOSE_ENDS_COUNTED_LABEL()}</dt>
          <dd>{countedInMembersValue(counted_in_members)}</dd>
        </div>
      </dl>
      <p className="text-muted-foreground text-xs">{looseEndsLiabilitySentence(formatPoints(balance))}</p>
    </div>
  );
}
