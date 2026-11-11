import { useFormContext, useWatch } from 'react-hook-form';

import type { AdvertConfig } from '@/lib/schema/campaign.schema';
import { Separator } from '@/components/ui/separator';
import { StorefrontTeaserField } from '../../shared/StorefrontTeaserField';
import { useConditionsPhraseQuery } from '../conditions-preview.queries';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';

/**
 * The storefront teaser, under the conditions it words; nothing while the campaign has none, since
 * only a campaign with conditions is teased. The Auto preview is the saved campaign's, so a new one shows none.
 */
export function CampaignEditorTeaser() {
  const { control, setValue } = useFormContext<CampaignFormValues>();
  const { mode, campaign } = useCampaignEditorContext();
  const conditions = useWatch({ control, name: 'conditions' });
  const config = useWatch({ control, name: 'config' });
  const hasConditions = Array.isArray(conditions) && conditions.length > 0;
  const phrase = useConditionsPhraseQuery(campaign.id, mode === 'edit' && hasConditions);

  if (!hasConditions) {
    return null;
  }

  function patchConfig(patch: AdvertConfig) {
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  return (
    <>
      <Separator />
      <StorefrontTeaserField
        config={config}
        onPatch={patchConfig}
        autoPhrase={phrase.isSuccess ? phrase.data : undefined}
      />
    </>
  );
}
