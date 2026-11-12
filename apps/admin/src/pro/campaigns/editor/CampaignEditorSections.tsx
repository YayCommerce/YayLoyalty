import { useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import type { AdvertConfig } from '@/lib/schema/campaign.schema';
import { EditorSection } from '@/components/ui/custom/editor-section';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { StorefrontTeaserField } from '../../shared/StorefrontTeaserField';
import { useConditionsPhraseQuery } from '../conditions-preview.api';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';
import { CampaignEditorConditions } from './CampaignEditorConditions';
import { CampaignEditorEarn } from './CampaignEditorEarn';

/** The editor's three sections in order. Status and schedule sit in the rail; see CampaignEditorSummaryRail. */
export function CampaignEditorSections() {
  return (
    <div className="flex flex-col gap-4">
      <EditorSection step={1} title={__('Basics', 'yayloyalty')}>
        <CampaignEditorBasics />
      </EditorSection>

      <EditorSection step={2} title={__('Reward', 'yayloyalty')}>
        <CampaignEditorEarn />
      </EditorSection>

      <EditorSection step={3} title={__('Conditions', 'yayloyalty')}>
        <CampaignEditorConditions />
        <CampaignEditorTeaser />
      </EditorSection>
    </div>
  );
}

/** The name. The slug is never shown or edited. */
function CampaignEditorBasics() {
  const { register, formState } = useFormContext<CampaignFormValues>();

  return (
    <>
      <Field data-invalid={!!formState.errors.name}>
        <FieldLabel htmlFor="campaign-basics-name">{__('Name', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Input id="campaign-basics-name" aria-invalid={!!formState.errors.name} {...register('name')} />
          <FieldError errors={[formState.errors.name]} />
        </FieldContent>
      </Field>

      <FieldDescription>
        {__('The customer never sees the campaign name — it is for your team only.', 'yayloyalty')}
      </FieldDescription>
    </>
  );
}

/**
 * The storefront teaser, under the conditions it words; nothing while the campaign has none, since
 * only a campaign with conditions is teased. The Auto preview is the saved campaign's, so a new one shows none.
 */
function CampaignEditorTeaser() {
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
