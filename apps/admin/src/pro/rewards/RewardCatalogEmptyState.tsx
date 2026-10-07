import { Gift } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { templateExample } from './editor/reward-editor.form';
import { REWARD_TEMPLATE_ORDER, templateTitle, typeLabel } from './reward-labels';

/**
 * The 2a empty state: a proper Empty panel explaining what a catalog reward
 * is (RewardCatalogEmptyState), plus one starting-point card per catalog
 * type (RewardCatalogTemplateCards), rendered as its own section below the
 * table rather than crammed into a table row. Both only render while there
 * is no catalog reward yet; a card opens the editor for that type.
 */

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
  const navigate = useNavigate();

  return (
    <div>
      <p className="mb-3 text-sm font-medium">
        {__('Start with one of these', 'yayloyalty')}{' '}
        <span className="text-muted-foreground font-normal">
          {__('each opens the editor with its fields filled in, as a draft', 'yayloyalty')}
        </span>
      </p>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {REWARD_TEMPLATE_ORDER.map((type) => (
          <button
            key={type}
            type="button"
            className="flex h-full text-left outline-none"
            onClick={() => navigate(`/rewards/new/${type}`)}
          >
            <Card className="hover:border-primary focus-visible:border-primary size-full gap-2 p-4 transition-colors">
              <CardContent className="flex h-full flex-col gap-2 p-0">
                <Badge variant="outline" className="w-fit uppercase">
                  {typeLabel(type)}
                </Badge>
                <p className="text-sm font-medium">{templateTitle(type)}</p>
                <p className="text-muted-foreground flex-1 text-xs">{templateExample(type)}</p>
              </CardContent>
            </Card>
          </button>
        ))}
      </div>
    </div>
  );
}
