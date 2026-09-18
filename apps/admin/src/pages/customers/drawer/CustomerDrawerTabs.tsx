import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ActivityLogTab } from './tabs/ActivityLogTab';
import { LedgerTab } from './tabs/LedgerTab';
import { RedemptionsTab } from './tabs/RedemptionsTab';
import { ReferralsTab } from './tabs/ReferralsTab';
import { TAB_ACTIVITY, TAB_LEDGER, TAB_REDEMPTIONS, TAB_REFERRALS } from './customer-drawer.copy';

/**
 * One tab: label with its count beside it, at the smaller, lighter weight
 * every artboard gives the count so it reads as an annotation on the label
 * rather than part of it (3c note 2).
 *
 * The classes undo the shared `TabsTrigger`'s segmented-pill look — the
 * drawer's bar is the underlined kind 1e/1f/3c draw, and this is the only
 * `Tabs` instance in the app, so it is overridden here at the call site
 * instead of adding a variant to the primitive nothing else would use.
 */
function DrawerTab({ value, label, count }: { value: string; label: string; count: number }) {
  return (
    <TabsTrigger
      value={value}
      // `-mb-px` pulls the active tab's 2px underline over the list's own 1px
      // rule instead of stacking a 3px line on top of it. That needs the list
      // itself to stay `overflow: visible` — see `TabsList`'s own comment.
      className="data-[state=active]:border-primary data-[state=active]:text-primary group -mb-px h-auto flex-none rounded-none border-0 border-b-2 border-transparent px-0 py-2.5 font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none"
    >
      {label}
      <span className="text-muted-foreground group-data-[state=active]:text-primary text-xs font-normal tabular-nums">
        {formatPoints(count)}
      </span>
    </TabsTrigger>
  );
}

/**
 * Points ledger · Activity log · Redemptions · Referrals, each labelled with
 * its own count from `customer.counts` — always present here (this only
 * mounts once `CustomerDrawer.tsx`'s identity query has resolved, so there
 * is no separate "counts still loading" state to render for). All four tabs
 * exist in both builds (locked decision 6) — no `IS_PRO_BUILD` branch.
 *
 * Ledger and Activity log have real, working bodies (Wave B wires the
 * latter — see `ActivityLogTab.tsx`). Redemptions/Referrals still render
 * their empty state ONLY, whatever their count says: their backing tables
 * do not exist yet (see each tab's own docblock), so a populated view would
 * be unreviewable against reality.
 */
export function CustomerDrawerTabs({ customer, scenario }: { customer: Customer; scenario?: string }) {
  const firstName = customer.name.split(' ')[0] || customer.name;

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
          <DrawerTab value="redemptions" label={TAB_REDEMPTIONS()} count={customer.counts.redemptions} />
          <DrawerTab value="referrals" label={TAB_REFERRALS()} count={customer.counts.referrals} />
        </TabsList>
      </div>
      <TabsContent value="ledger">
        <LedgerTab userId={customer.user_id} customerFirstName={firstName} scenario={scenario} />
      </TabsContent>
      <TabsContent value="activity">
        <ActivityLogTab userId={customer.user_id} scenario={scenario} />
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
