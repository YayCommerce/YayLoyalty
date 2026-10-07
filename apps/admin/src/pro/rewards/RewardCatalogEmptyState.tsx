import { Gift } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';

/** The Rewards table's empty state while no catalog reward exists yet. */
export function RewardCatalogEmptyState() {
  return (
    <Empty>
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <Gift />
        </EmptyMedia>
        <EmptyTitle>{__('No catalog rewards yet', 'yayloyalty')}</EmptyTitle>
        <EmptyDescription>
          {__('Customers spend points on these for a coupon to use later.', 'yayloyalty')}
        </EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}
