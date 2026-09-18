import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ActivityLogTab } from './tabs/ActivityLogTab';
import { LedgerTab } from './tabs/LedgerTab';
import { RedemptionsTab } from './tabs/RedemptionsTab';
import { ReferralsTab } from './tabs/ReferralsTab';
import { TAB_ACTIVITY, TAB_LEDGER, TAB_REDEMPTIONS, TAB_REFERRALS } from './customer-drawer.copy';

/**
 * Points ledger · Activity log · Redemptions · Referrals, each labelled with
 * its own count from `customer.counts` — always present here (this only
 * mounts once `CustomerDrawer.tsx`'s identity query has resolved, so there
 * is no separate "counts still loading" state to render for). All four tabs
 * exist in both builds (locked decision 6) — no `IS_PRO_BUILD` branch.
 *
 * Only the ledger tab has a real, working body — Activity log/Redemptions/
 * Referrals render their empty state ONLY, whatever their count says: their
 * backing tables do not exist yet (see each tab's own docblock), so a
 * populated view would be unreviewable against reality.
 */
export function CustomerDrawerTabs({ customer, scenario }: { customer: Customer; scenario?: string }) {
  const firstName = customer.name.split(' ')[0] || customer.name;

  return (
    <Tabs defaultValue="ledger">
      <TabsList>
        <TabsTrigger value="ledger">
          {TAB_LEDGER()} {formatPoints(customer.counts.ledger)}
        </TabsTrigger>
        <TabsTrigger value="activity">
          {TAB_ACTIVITY()} {formatPoints(customer.counts.activity)}
        </TabsTrigger>
        <TabsTrigger value="redemptions">
          {TAB_REDEMPTIONS()} {formatPoints(customer.counts.redemptions)}
        </TabsTrigger>
        <TabsTrigger value="referrals">
          {TAB_REFERRALS()} {formatPoints(customer.counts.referrals)}
        </TabsTrigger>
      </TabsList>
      <TabsContent value="ledger">
        <LedgerTab userId={customer.user_id} customerFirstName={firstName} scenario={scenario} />
      </TabsContent>
      <TabsContent value="activity">
        <ActivityLogTab />
      </TabsContent>
      <TabsContent value="redemptions">
        <RedemptionsTab customerFirstName={firstName} />
      </TabsContent>
      <TabsContent value="referrals">
        <ReferralsTab />
      </TabsContent>
    </Tabs>
  );
}
