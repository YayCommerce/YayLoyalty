import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { CatalogRewardType } from '@/lib/schema/reward.schema';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BackLink } from '@/components/ui/custom/back-link';
import PageLayout from '@/components/layout/PageLayout';
import { templateExample } from '../editor/reward-editor.form';
import { REWARD_TEMPLATE_ORDER, templateDescription, templateTitle, typeLabel } from '../reward-labels';

/**
 * "Add a reward" chooser: one card per catalog type, each opening the editor
 * in create mode via rewards/new/:type. No flexible-discount card: the
 * built-in slider is the only one and is edited, never added.
 */
export function RewardTemplatePicker() {
  const navigate = useNavigate();

  return (
    <>
      <BackLink to="/rewards" label={__('Rewards', 'yayloyalty')} />

      <PageLayout
        className="max-w-6xl"
        title={__('Add a reward', 'yayloyalty')}
        description={__(
          'Pick what customers claim with their points, then adjust it. Nothing is created until you save.',
          'yayloyalty',
        )}
      >
        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
          {REWARD_TEMPLATE_ORDER.map((type) => (
            <RewardTemplateCard key={type} type={type} onSelect={() => navigate(`/rewards/new/${type}`)} />
          ))}
        </div>
      </PageLayout>
    </>
  );
}

function RewardTemplateCard({ type, onSelect }: { type: CatalogRewardType; onSelect: () => void }) {
  return (
    <button type="button" onClick={onSelect} className="flex h-full text-left outline-none">
      <Card className="hover:border-primary focus-visible:border-primary size-full transition-colors">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            {templateTitle(type)}
            <Badge variant="outline" className="uppercase">
              {typeLabel(type)}
            </Badge>
          </CardTitle>
        </CardHeader>
        <CardContent className="flex h-full flex-col gap-3">
          <p className="text-muted-foreground flex-1 text-sm">{templateDescription(type)}</p>
          <p className="text-foreground border-t pt-3 text-xs">{templateExample(type)}</p>
        </CardContent>
      </Card>
    </button>
  );
}
