import { formatPoints } from '@/lib/helpers/points.helper';
import type { LooseEnds } from '@/lib/schema/customer.schema';
import {
  countedInMembersValue,
  LOOSE_ENDS_COUNTED_LABEL,
  LOOSE_ENDS_TITLE,
  LOOSE_ENDS_VOID_COUPONS_LABEL,
  looseEndsLiabilitySentence,
  voidCouponsValue,
} from './customer-drawer.panels.copy';

/**
 * What a deletion leaves behind: void coupons and whether the member total counts them.
 * Each row renders even when its value is null, as "Not tracked yet".
 */
export function CustomerDrawerLooseEnds({ looseEnds, balance }: { looseEnds: LooseEnds; balance: number }) {
  const { void_coupons, counted_in_members } = looseEnds;

  return (
    <div className="flex flex-col gap-3 rounded-md border p-3.5">
      <p className="text-sm font-medium">{LOOSE_ENDS_TITLE()}</p>
      <dl className="flex flex-col gap-2 text-sm">
        <div className="flex items-center justify-between gap-3">
          <dt className="text-muted-foreground">{LOOSE_ENDS_VOID_COUPONS_LABEL()}</dt>
          <dd className="tabular-nums">{voidCouponsValue(void_coupons)}</dd>
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
