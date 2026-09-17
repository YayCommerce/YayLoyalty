import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { useCustomerQuery } from '@/lib/queries/customer.queries';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { ErrorState } from '@/components/ui/custom/error-state';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CustomerDrawerHeader } from './CustomerDrawerHeader';
import { CustomerDrawerSkeleton } from './CustomerDrawerSkeleton';
import { useDrawerScenario } from './customer-drawer.scenario';
import { DrawerBackLink } from './DrawerBackLink';

/**
 * Sheet shell for the `customers/:userId` child route (see `router.tsx`) —
 * rendered through `CustomersPage`'s `<Outlet/>`, so the list underneath
 * stays mounted, dimmed, and keeps its own filters/page/sort/selection while
 * this is open. Owns route params, the identity query, and open/close.
 * Stats, actions and tabs are composed in here by phases 05-06 — this phase
 * only wires the shell, the loading skeleton and the error/not-found state.
 *
 * Below the `sm` breakpoint `SheetContent` fills the screen (5c) instead of
 * showing as a 600px panel — one component, driven by the CSS breakpoint on
 * its own width classes, not a second component tree.
 */
export default function CustomerDrawer() {
  const { userId: userIdParam } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const scenario = useDrawerScenario();

  const userId = Number(userIdParam);
  const isValidId = Number.isFinite(userId) && userId >= 1;

  // `enabled: userId > 0` on the query itself (see customer.queries.ts) means
  // passing 0 here for an invalid id never fires a request.
  const query = useCustomerQuery(isValidId ? userId : 0, scenario);
  const listRow = (location.state as { row?: CustomerListRow } | null)?.row;
  const search = searchParams.toString();

  function close() {
    navigate({ pathname: '/customers', search: search ? `?${search}` : '' });
  }

  return (
    <Sheet
      open
      onOpenChange={(open) => {
        if (!open) close();
      }}
    >
      <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-150">
        {!isValidId || query.isError ? (
          <div className="flex flex-1 flex-col gap-4 p-5">
            {/* No identity to show — a plain sr-only title/description (Radix
                Dialog requires both) plus the 5c back link, not the full
                `CustomerDrawerHeader` (its skeleton fallback would falsely
                imply this is still loading). */}
            <SheetHeader className="gap-2 p-0">
              <DrawerBackLink onClick={close} />
              <SheetTitle className="sr-only">{__('Customer not available', 'yayloyalty')}</SheetTitle>
              <SheetDescription className="sr-only">
                {__('This customer could not be shown.', 'yayloyalty')}
              </SheetDescription>
            </SheetHeader>
            <ErrorState
              message={
                !isValidId
                  ? __('This customer could not be found.', 'yayloyalty')
                  : __('Could not load this customer.', 'yayloyalty')
              }
              onRetry={isValidId ? () => query.refetch() : undefined}
            />
          </div>
        ) : (
          <>
            <CustomerDrawerHeader customer={query.data} listRow={listRow} onClose={close} />
            <ScrollArea className="flex-1">
              <div className="p-5">{query.isPending ? <CustomerDrawerSkeleton /> : null}</div>
            </ScrollArea>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
