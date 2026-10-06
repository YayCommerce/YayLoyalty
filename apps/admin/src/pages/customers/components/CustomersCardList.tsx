import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import { CustomerCardRow } from './CustomerCardRow';
import { CustomersEmptyState } from './CustomersEmptyState';

/** The mobile list body. Its loading, empty and no-match states match CustomersTable's, so the two layouts never disagree. */
export function CustomersCardList({
  rows,
  isLoading,
  isFetching,
  isStoreEmpty,
  noMatchContent,
  showProColumns,
}: {
  rows: CustomerListRow[];
  isLoading: boolean;
  isFetching: boolean;
  isStoreEmpty: boolean;
  noMatchContent?: ReactNode;
  showProColumns: boolean;
}) {
  const isEmpty = !isLoading && rows.length === 0;

  return (
    <div className={cn('divide-y rounded-md border transition-opacity', isFetching && !isLoading && 'opacity-60')}>
      {isLoading && <CardSkeletonRows />}
      {isEmpty && (
        <div className="py-12">
          {isStoreEmpty ? (
            <CustomersEmptyState />
          ) : (
            (noMatchContent ?? (
              <p className="text-muted-foreground px-3.5 text-center text-sm">
                {__('No customers match these filters.', 'yayloyalty')}
              </p>
            ))
          )}
        </div>
      )}
      {!isLoading &&
        rows.map((row) => <CustomerCardRow key={row.user_id} customer={row} showProColumns={showProColumns} />)}
    </div>
  );
}

function CardSkeletonRows() {
  return (
    <>
      {Array.from({ length: 4 }).map((_, index) => (
        <div key={index} className="flex flex-col gap-2 p-3.5">
          <div className="flex items-center justify-between gap-3">
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
              <Skeleton className="h-3 w-32" />
              <Skeleton className="h-2.5 w-40" />
            </div>
            <Skeleton className="h-3 w-14" />
          </div>
          <Skeleton className="h-2.5 w-24" />
        </div>
      ))}
    </>
  );
}
