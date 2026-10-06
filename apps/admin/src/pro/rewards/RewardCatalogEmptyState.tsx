import { Gift } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { CatalogRewardType } from '@/lib/schema/reward.schema';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { typeLabel } from './reward-labels';

/**
 * The 2a empty state: a proper Empty panel explaining what a catalog reward
 * is (RewardCatalogEmptyState), plus one starting-point card per catalog
 * type (RewardCatalogTemplateCards), rendered as its own section below the
 * table rather than crammed into a table row. Both only render while there
 * is no catalog reward yet, and both are UI-only — a card click does
 * nothing, since there is no reward editor to open a draft in yet.
 */

const TEMPLATES: { type: CatalogRewardType; title: string; example: string }[] = [
  {
    type: 'fixed_coupon',
    title: __('Fixed discount', 'yayloyalty'),
    example: __('Example: $5 off orders over $25, for 500 points', 'yayloyalty'),
  },
  {
    type: 'free_shipping',
    title: __('Free shipping', 'yayloyalty'),
    example: __('Example: free shipping on one order, for 800 points', 'yayloyalty'),
  },
  {
    type: 'percent_coupon',
    title: __('Percentage off', 'yayloyalty'),
    example: __('Example: 15% off, for 1,500 points', 'yayloyalty'),
  },
  {
    type: 'free_product',
    title: __('Free product', 'yayloyalty'),
    example: __('Example: a free product, 100 available, for 3,000 points', 'yayloyalty'),
  },
];

export function RewardCatalogEmptyState() {
  return (
    <Empty>
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <Gift />
        </EmptyMedia>
        <EmptyTitle>{__('No catalog rewards yet', 'yayloyalty')}</EmptyTitle>
        <EmptyDescription>
          {__(
            'Catalog rewards are claimed with points for a coupon the customer uses later. Add one to give customers something to save towards.',
            'yayloyalty',
          )}
        </EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}

export function RewardCatalogTemplateCards() {
  return (
    <div>
      <p className="mb-3 text-sm font-medium">
        {__('Start with one of these', 'yayloyalty')}{' '}
        <span className="text-muted-foreground font-normal">
          {__('each opens the editor with its fields filled in, as a draft', 'yayloyalty')}
        </span>
      </p>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {TEMPLATES.map((template) => (
          <button key={template.type} type="button" className="flex h-full text-left outline-none">
            <Card className="hover:border-primary focus-visible:border-primary size-full gap-2 p-4 transition-colors">
              <CardContent className="flex h-full flex-col gap-2 p-0">
                <Badge variant="outline" className="w-fit uppercase">
                  {typeLabel(template.type)}
                </Badge>
                <p className="text-sm font-medium">{template.title}</p>
                <p className="text-muted-foreground flex-1 text-xs">{template.example}</p>
              </CardContent>
            </Card>
          </button>
        ))}
      </div>
    </div>
  );
}
