import { parseWPDate, utcToDate } from '@/lib/helpers/format.helper';
import type { Customer, CustomerListRow } from '@/lib/schema/customer.schema';
import { Badge } from '@/components/ui/badge';
import { SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { isProBuild } from '@/components/pro/ProGate';
import { ACCOUNT_DELETED_LABEL, memberSince } from './customer-drawer.copy';
import { CustomerDrawerTierCard } from './CustomerDrawerTierCard';
import { DrawerBackLink } from './DrawerBackLink';

/**
 * Identity block: name, email, member-since, the `ACCOUNT DELETED` badge
 * (4a's header treatment only — the full loose-ends panel is out of scope
 * for Wave A), and the `‹ Customers` back link 5c shows below the `sm`
 * breakpoint (the Sheet becomes a full-screen view there, so a back link
 * replaces relying on the overlay click to close it).
 *
 * `listRow` (from the clicked list row's router state) renders name/email
 * instantly; `customer` (the fetched resource) is what a deep link or a
 * refresh has instead — see CustomerDrawer.tsx. `SheetTitle`/`SheetDescription`
 * wrap them for Radix Dialog's a11y requirements.
 */
export function CustomerDrawerHeader({
  customer,
  listRow,
  onClose,
}: {
  customer: Customer | undefined;
  listRow: CustomerListRow | undefined;
  onClose: () => void;
}) {
  const name = listRow?.name ?? customer?.name;
  const email = listRow?.email ?? customer?.email;
  const isDeleted = !!customer?.deleted_at;
  return (
    <SheetHeader className="gap-2">
      <DrawerBackLink onClick={onClose} />
      <div className="flex flex-wrap items-center gap-2 pr-8">
        <SheetTitle>{name ?? <Skeleton className="h-5 w-32" />}</SheetTitle>
        {isDeleted && <Badge variant="destructive">{ACCOUNT_DELETED_LABEL()}</Badge>}
      </div>
      <SheetDescription>
        {email ?? <Skeleton className="h-4 w-40" />}
        {customer?.joined_at ? ` · ${memberSince(parseWPDate(utcToDate(customer.joined_at)))}` : null}
      </SheetDescription>
      {isProBuild() && customer && <CustomerDrawerTierCard customer={customer} />}
    </SheetHeader>
  );
}
