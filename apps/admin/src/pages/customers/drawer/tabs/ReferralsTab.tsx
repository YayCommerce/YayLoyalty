import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { EMPTY_REFERRALS_BODY, EMPTY_REFERRALS_TITLE } from '../customer-drawer.copy';

/**
 * Wave A renders the empty state only — the backing table does not exist
 * yet (see plans/260917-1504-customer-drawer-wave-a/plan.md → Scope;
 * `docs/data-model.md`'s `yayloyalty_referrals` is a 1.1 table). Never a
 * populated table here, whatever the fixture's `counts.referrals` says.
 */
export function ReferralsTab() {
  return (
    <Empty className="border-none">
      <EmptyHeader>
        <EmptyTitle>{EMPTY_REFERRALS_TITLE()}</EmptyTitle>
        <EmptyDescription>{EMPTY_REFERRALS_BODY()}</EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}
