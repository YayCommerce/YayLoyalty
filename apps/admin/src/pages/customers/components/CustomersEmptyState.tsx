import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';

/**
 * "No members yet" — a genuinely empty store (`GET /customers` reported a
 * total of zero with no filters active), not a search/filter that happens
 * to match nobody. Those are
 * different situations for a merchant: this one explains what makes a member
 * appear; a filtered-to-zero result says so instead (see CustomersTable.tsx).
 */
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
