import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { Customer } from '@/lib/schema/customer.schema';
import { Tabs, TabsContent, TabsList } from '@/components/ui/tabs';
import { ProGate } from '@/components/pro/ProGate';
import { CustomerReferralsTab, CustomerReferralsTrigger } from '@/pro';
import { HISTORY_TITLE, TAB_ACTIVITY, TAB_LEDGER, TAB_REDEMPTIONS, TAB_REFERRALS } from './customer-drawer.copy';
import { ActivityLogTab } from './tabs/ActivityLogTab';
import { DrawerTab } from './tabs/DrawerTab';
import { LedgerTab } from './tabs/LedgerTab';
import { RedemptionsTab } from './tabs/RedemptionsTab';

/**
 * The "Customer history" card: four tabs, each labelled with its count: from `customer.counts`, and Referrals from its own Pro request.
 * Both builds show all four. In Lite, `ProGate` swaps the Redemptions and Referrals bodies for the upgrade surface.
 * It is a presentation gate, not access control.
 */
export function CustomerDrawerTabs({
  customer,
  customerFirstName: firstName,
}: {
  customer: Customer;
  customerFirstName: string;
}) {
  return (
    <Tabs defaultValue="ledger" className="gap-0 rounded-lg border">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
        <h3 className="m-0 text-sm font-semibold">{HISTORY_TITLE()}</h3>
        {/* The scroll wrapper keeps the pill group usable when four tabs don't fit a phone. */}
        <div className="max-w-full overflow-x-auto">
          <TabsList className="h-auto w-max">
            <DrawerTab value="ledger" label={TAB_LEDGER()} count={customer.counts.ledger} />
            <DrawerTab value="activity" label={TAB_ACTIVITY()} count={customer.counts.activity} />
            <DrawerTab value="redemptions" label={TAB_REDEMPTIONS()} count={customer.counts.redemptions} proGated />
            {IS_PRO_BUILD ? (
              <CustomerReferralsTrigger userId={customer.user_id} />
            ) : (
              <DrawerTab value="referrals" label={TAB_REFERRALS()} count={0} proGated />
            )}
          </TabsList>
        </div>
      </div>
      <TabsContent value="ledger" className="p-4">
        <LedgerTab customer={customer} customerFirstName={firstName} />
      </TabsContent>
      <TabsContent value="activity" className="p-4">
        <ActivityLogTab userId={customer.user_id} />
      </TabsContent>
      <TabsContent value="redemptions" className="p-4">
        <ProGate>
          <RedemptionsTab userId={customer.user_id} customerFirstName={firstName} />
        </ProGate>
      </TabsContent>
      <TabsContent value="referrals" className="p-4">
        {IS_PRO_BUILD ? (
          <CustomerReferralsTab
            userId={customer.user_id}
            customerFirstName={firstName}
            lifetimeEarned={customer.lifetime_earned}
          />
        ) : (
          <ProGate />
        )}
      </TabsContent>
    </Tabs>
  );
}
