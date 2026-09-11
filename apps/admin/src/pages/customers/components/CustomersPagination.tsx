import { sprintf, __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * "{shown} of {total}" + Previous/Next. Rolled locally rather than the shared
 * `Pagination` primitive, which renders "Page X of Y" with a go-to-page
 * number input — not what this list needs (`CampaignsPage` sets the same
 * precedent for an inline pager).
 *
 * Previous/Next stay disabled: there is no real pagination behind this demo
 * list (it is every row there is), so claiming a page 2 exists would be
 * dishonest rather than merely unfinished.
 */
export function CustomersPagination({
  shown,
  total,
  isLoading,
}: {
  shown: number;
  total: number;
  isLoading: boolean;
}) {
  return (
    <div className="text-muted-foreground flex items-center gap-3 text-xs">
      {isLoading ? (
        <Skeleton className="h-3 w-20" />
      ) : (
        <span>{sprintf(__('%1$d of %2$d', 'yayloyalty'), shown, total)}</span>
      )}
      <div className="ml-auto flex items-center gap-1.5">
        <Button variant="outline" size="sm" disabled>
          {__('‹ Previous', 'yayloyalty')}
        </Button>
        <Button variant="outline" size="sm" disabled>
          {__('Next ›', 'yayloyalty')}
        </Button>
      </div>
    </div>
  );
}
