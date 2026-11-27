import { UserIcon } from 'lucide-react';

import { formatStoredDate } from '@/lib/helpers/format.helper';
import type { Customer, CustomerListRow } from '@/lib/schema/customer.schema';
import { Badge } from '@/components/ui/badge';
import { SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { ACCOUNT_DELETED_LABEL, memberSince } from './customer-drawer.copy';
import { DrawerBackLink } from './DrawerBackLink';

/**
 * Identity block: name, email, member-since, the deleted badge and the mobile back link.
 * `listRow`, from the clicked row's router state, renders identity at once. A deep link or a refresh waits for `customer`.
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
    <SheetHeader className="gap-2 border-b">
      <DrawerBackLink onClick={onClose} />
      <div className="flex items-center gap-3 pr-8">
        <span className="bg-muted text-muted-foreground flex size-11 shrink-0 items-center justify-center rounded-full">
          <UserIcon className="size-5" />
        </span>
        <div className="flex min-w-0 flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <SheetTitle className="text-lg">{name ?? <Skeleton className="h-5 w-32" />}</SheetTitle>
            {isDeleted && <Badge variant="destructive">{ACCOUNT_DELETED_LABEL()}</Badge>}
          </div>
          {/* A div, not Radix's default <p>: the loading Skeleton is a <div>, which a <p> can't hold. */}
          <SheetDescription asChild>
            <div className="text-xs">
              {email ?? <Skeleton className="h-4 w-40" />}
              {customer?.joined_at ? ` · ${memberSince(formatStoredDate(customer.joined_at))}` : null}
            </div>
          </SheetDescription>
        </div>
      </div>
    </SheetHeader>
  );
}
