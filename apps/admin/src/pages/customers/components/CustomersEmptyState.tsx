import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';

/** "No members yet": a genuinely empty store, not filters matching nobody. */
export function CustomersEmptyState() {
  return (
    <Empty className="w-full border-none">
      <EmptyHeader>
        <EmptyTitle>{__('No members yet', 'yayloyalty')}</EmptyTitle>
        <EmptyDescription>
          {__(
            'A customer appears here the first time they earn points — from an order, an account signup or a product review.',
            'yayloyalty',
          )}
        </EmptyDescription>
      </EmptyHeader>
      <EmptyContent className="flex-row justify-center gap-4">
        <Link to="/campaigns" className="text-primary text-sm hover:underline">
          {__('Check campaigns', 'yayloyalty')}
        </Link>
        <Link to="/rewards" className="text-primary text-sm hover:underline">
          {__('Check rewards', 'yayloyalty')}
        </Link>
      </EmptyContent>
    </Empty>
  );
}
