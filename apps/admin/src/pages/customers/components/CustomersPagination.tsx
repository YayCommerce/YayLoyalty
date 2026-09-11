import { sprintf, __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Real pagination over the sorted, filtered rows — see CustomersScreen.tsx
 * for the slicing. Rolled locally rather than the shared `Pagination`
 * primitive, which renders "Page X of Y" with a go-to-page number input —
 * not what this list needs (`CampaignsPage` sets the same precedent for an
 * inline pager).
 */
export function CustomersPagination({
  page,
  pageCount,
  total,
  perPage,
  isLoading,
  onPrevious,
  onNext,
  /** e.g. "· balances as of 11:02" during the rebuild toggle — see CustomersRebuildBanner.tsx. */
  note,
}: {
  page: number;
  pageCount: number;
  total: number;
  perPage: number;
  isLoading: boolean;
  onPrevious: () => void;
  onNext: () => void;
  note?: string;
}) {
  const rangeStart = total === 0 ? 0 : (page - 1) * perPage + 1;
  const rangeEnd = Math.min(page * perPage, total);

  return (
    <div className="text-muted-foreground flex items-center gap-3 text-xs">
      {isLoading ? (
        <Skeleton className="h-3 w-20" />
      ) : (
        <span>
          {sprintf(__('%1$s of %2$d', 'yayloyalty'), `${rangeStart}–${rangeEnd}`, total)}
          {note ? ` ${note}` : ''}
        </span>
      )}
      <div className="ml-auto flex items-center gap-1.5">
        <Button variant="outline" size="sm" disabled={isLoading || page <= 1} onClick={onPrevious}>
          {__('‹ Previous', 'yayloyalty')}
        </Button>
        <Button variant="outline" size="sm" disabled={isLoading || page >= pageCount} onClick={onNext}>
          {__('Next ›', 'yayloyalty')}
        </Button>
      </div>
    </div>
  );
}
