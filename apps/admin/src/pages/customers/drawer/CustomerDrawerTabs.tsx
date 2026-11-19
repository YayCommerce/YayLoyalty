import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { Customer } from '@/lib/schema/customer.schema';
import { Tabs, TabsContent, TabsList } from '@/components/ui/tabs';
import { ProGate } from '@/components/pro/ProGate';
import { CustomerReferralsTab, CustomerReferralsTrigger } from '@/pro';
import { TAB_ACTIVITY, TAB_LEDGER, TAB_REDEMPTIONS, TAB_REFERRALS } from './customer-drawer.copy';
import { ActivityLogTab } from './tabs/ActivityLogTab';
import { DrawerTab } from './tabs/DrawerTab';
import { LedgerTab } from './tabs/LedgerTab';
import { RedemptionsTab } from './tabs/RedemptionsTab';

/**
 * The four tabs, each labelled with its count: from `customer.counts`, and Referrals from its own Pro request.
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
    <Tabs defaultValue="ledger">
      {/* The scroll container is this wrapper, never `TabsList` itself:
          `overflow-x` forces `overflow-y` off `visible`, which would clip the
          triggers' `-mb-px` underline and their focus rings. `w-max min-w-full`
          lets the bar grow past the drawer below `sm` while its bottom rule
          still spans the full width when the three tabs fit. */}
      <div className="overflow-x-auto">
        <TabsList className="h-auto w-max min-w-full justify-start gap-5 rounded-none border-b bg-transparent p-0">
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
      <TabsContent value="ledger">
        <LedgerTab
          userId={customer.user_id}
          customerFirstName={firstName}
          causeLedgerId={customer.negative_balance?.cause_ledger_id ?? null}
        />
      </TabsContent>
      <TabsContent value="activity">
        <ActivityLogTab userId={customer.user_id} />
      </TabsContent>
      <TabsContent value="redemptions">
        <ProGate>
          <RedemptionsTab userId={customer.user_id} customerFirstName={firstName} />
        </ProGate>
      </TabsContent>
      <TabsContent value="referrals">
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
