import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ProGate } from '@/components/pro/ProGate';
import { TAB_ACTIVITY, TAB_LEDGER, TAB_REDEMPTIONS, TAB_REFERRALS } from './customer-drawer.copy';
import { ActivityLogTab } from './tabs/ActivityLogTab';
import { LedgerTab } from './tabs/LedgerTab';
import { RedemptionsTab } from './tabs/RedemptionsTab';
import { ReferralsTab } from './tabs/ReferralsTab';

/**
 * One tab, with its count beside the label in a smaller, lighter weight.
 * The classes replace the shared TabsTrigger's pill look with an underline at the call site.
 */
function DrawerTab({
  value,
  label,
  count,
  proGated = false,
}: {
  value: string;
  label: string;
  count: number;
  /** A Pro badge in place of the count. */
  proGated?: boolean;
}) {
  return (
    <TabsTrigger
      value={value}
      // `-mb-px` pulls the active tab's 2px underline over the list's 1px rule, so they do not stack.
      // That needs the list to stay `overflow: visible`.
      className="data-[state=active]:border-primary data-[state=active]:text-primary group -mb-px h-auto flex-none rounded-none border-0 border-b-2 border-transparent px-0 py-2.5 font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none"
    >
      {label}

      {proGated ? (
        <ProGate inline />
      ) : (
        <span className="text-muted-foreground group-data-[state=active]:text-primary text-xs font-normal tabular-nums">
          {formatPoints(count)}
        </span>
      )}
    </TabsTrigger>
  );
}

/**
 * The four tabs, each labelled with its count from `customer.counts`. Both builds show all four.
 * In Lite, `ProGate` swaps the Redemptions and Referrals bodies for the upgrade surface. It is a presentation gate, not access control.
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
          still spans the full width when the four tabs fit. */}
      <div className="overflow-x-auto">
        <TabsList className="h-auto w-max min-w-full justify-start gap-5 rounded-none border-b bg-transparent p-0">
          <DrawerTab value="ledger" label={TAB_LEDGER()} count={customer.counts.ledger} />
          <DrawerTab value="activity" label={TAB_ACTIVITY()} count={customer.counts.activity} />
          <DrawerTab value="redemptions" label={TAB_REDEMPTIONS()} count={customer.counts.redemptions} proGated />
          <DrawerTab value="referrals" label={TAB_REFERRALS()} count={customer.counts.referrals} proGated />
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
        <ProGate>
          <ReferralsTab userId={customer.user_id} />
        </ProGate>
      </TabsContent>
    </Tabs>
  );
}
