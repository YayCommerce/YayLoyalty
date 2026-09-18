import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { emptyRedemptionsBody, EMPTY_REDEMPTIONS_TITLE } from '../customer-drawer.copy';

/**
 * Wave A renders the empty state only — the backing table does not exist
 * yet (see plans/260917-1504-customer-drawer-wave-a/plan.md → Scope;
 * `docs/data-model.md`'s `yayloyalty_redemptions` entry is designed but
 * NOT IMPLEMENTED). Never a populated table here, whatever the fixture's
 * `counts.redemptions` says.
 */
export function RedemptionsTab({ customerFirstName }: { customerFirstName: string }) {
  return (
    <Empty className="border-none">
      <EmptyHeader>
        <EmptyTitle>{EMPTY_REDEMPTIONS_TITLE()}</EmptyTitle>
        <EmptyDescription>{emptyRedemptionsBody(customerFirstName)}</EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}
