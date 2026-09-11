import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * "1–20 of 2,431" + Previous/Next. Rolled locally rather than the shared
 * `Pagination` primitive, which renders "Page X of Y" with a go-to-page
 * number input — not what this design calls for (`CampaignsPage` sets the
 * same precedent for an inline pager).
 */
export function CustomersPagination({
  rangeLabel,
  note,
  previousDisabled,
  nextDisabled,
  loading,
}: {
  rangeLabel: string;
  note?: string;
  previousDisabled: boolean;
  nextDisabled: boolean;
  loading?: boolean;
}) {
  return (
    <div className="text-muted-foreground flex items-center gap-3 text-xs">
      <span className="flex items-center gap-2">
        {rangeLabel}
        {loading && <Skeleton className="h-3 w-14" />}
      </span>
      {note && <span>{note}</span>}
      <div className="ml-auto flex items-center gap-1.5">
        <Button variant="outline" size="sm" disabled={previousDisabled}>
          {__('‹ Previous', 'yayloyalty')}
        </Button>
        <Button variant="outline" size="sm" disabled={nextDisabled}>
          {__('Next ›', 'yayloyalty')}
        </Button>
      </div>
    </div>
  );
}
