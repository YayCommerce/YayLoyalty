import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { EMPTY_ACTIVITY_BODY, EMPTY_ACTIVITY_TITLE } from '../customer-drawer.copy';

/**
 * Wave A renders the empty state only — the backing table does not exist
 * yet (see plans/260917-1504-customer-drawer-wave-a/plan.md → Scope). The
 * WP_DEBUG fixture generates activity rows server-side (`DrawerActivity.php`)
 * so the real endpoint's future shape is proven, but nothing here renders
 * them: a populated view would be unreviewable against a table that has no
 * design yet (see `docs/data-model.md`'s "Activity log (not designed)" note).
 */
export function ActivityLogTab() {
  return (
    <Empty className="border-none">
      <EmptyHeader>
        <EmptyTitle>{EMPTY_ACTIVITY_TITLE()}</EmptyTitle>
        <EmptyDescription>{EMPTY_ACTIVITY_BODY()}</EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}
