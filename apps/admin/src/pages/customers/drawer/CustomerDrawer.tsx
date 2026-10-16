import { __ } from '@wordpress/i18n';

import { useCustomerQuery } from '@/lib/queries/customer.queries';
import { useLocation, useNavigate, useParams, useSearchParams } from '@/lib/router';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CustomerDrawerActions } from './CustomerDrawerActions';
import { CustomerDrawerDeletedActions } from './CustomerDrawerDeletedActions';
import { CustomerDrawerDeletedBanner } from './CustomerDrawerDeletedBanner';
import { CustomerDrawerExpiryPanel } from './CustomerDrawerExpiryPanel';
import { CustomerDrawerExpiryStats } from './CustomerDrawerExpiryStats';
import { CustomerDrawerHeader } from './CustomerDrawerHeader';
import { CustomerDrawerLooseEnds } from './CustomerDrawerLooseEnds';
import { CustomerDrawerLotBatches } from './CustomerDrawerLotBatches';
import { CustomerDrawerNegativeActions } from './CustomerDrawerNegativeActions';
import { CustomerDrawerNegativePanel } from './CustomerDrawerNegativePanel';
import { CustomerDrawerSkeleton } from './CustomerDrawerSkeleton';
import { CustomerDrawerStats } from './CustomerDrawerStats';
import { CustomerDrawerTabs } from './CustomerDrawerTabs';
import { DrawerBackLink } from './DrawerBackLink';

/**
 * Sheet shell for the `customers/:userId` route, rendered through CustomersPage's `<Outlet/>` so the list stays mounted.
 * It owns the route params, the identity query, open and close, and the loading and error states.
 */
export default function CustomerDrawer() {
  const { userId: userIdParam } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();

  const userId = Number(userIdParam);
  const isValidId = Number.isFinite(userId) && userId >= 1;

  // 0 for an invalid id: the query stays disabled for it.
  const query = useCustomerQuery(isValidId ? userId : 0);
  const listRow = (location.state as { row?: CustomerListRow } | null)?.row;
  const search = searchParams.toString();
  // The '' fallback is never rendered: everything that reads this waits for `query.data`.
  const firstName = query.data ? query.data.name.split(' ')[0] || query.data.name : '';

  function close() {
    // Route-relative: back to whichever list opened the drawer, Customers or the Ledger.
    navigate({ pathname: '..', search: search ? `?${search}` : '' });
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
            {/* Radix Dialog needs a title and a description. Not CustomerDrawerHeader: its skeleton
                would suggest this is still loading. */}
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
            <div className="min-h-0 flex-1 overflow-y-auto">
              <div className="flex flex-col gap-5 p-5">
                {query.isPending || !query.data ? (
                  <CustomerDrawerSkeleton />
                ) : (
                  <>
                    <CustomerDrawerStats customer={query.data} />
                    <CustomerDrawerActions customer={query.data} isLoading={query.isFetching} />
                    {/* `deleted_at` and `loose_ends` are non-null together. Checking both gives the children non-null props. */}
                    {query.data.deleted_at && query.data.loose_ends && (
                      <>
                        <CustomerDrawerDeletedBanner
                          deletedAt={query.data.deleted_at}
                          keptEntries={query.data.loose_ends.kept_ledger_entries}
                        />
                        <CustomerDrawerDeletedActions userId={query.data.user_id} />
                        <CustomerDrawerLooseEnds looseEnds={query.data.loose_ends} balance={query.data.balance} />
                      </>
                    )}
                    {query.data.expiry && (
                      <>
                        <CustomerDrawerExpiryPanel customer={query.data} expiry={query.data.expiry} />
                        <CustomerDrawerExpiryStats expiry={query.data.expiry} />
                        <CustomerDrawerLotBatches batches={query.data.expiry.batches} />
                      </>
                    )}
                    {query.data.negative_balance && (
                      <>
                        <CustomerDrawerNegativePanel
                          customerFirstName={firstName}
                          negativeBalance={query.data.negative_balance}
                        />
                        <CustomerDrawerNegativeActions customerFirstName={firstName} />
                      </>
                    )}
                    <CustomerDrawerTabs customer={query.data} customerFirstName={firstName} />
                  </>
                )}
              </div>
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
