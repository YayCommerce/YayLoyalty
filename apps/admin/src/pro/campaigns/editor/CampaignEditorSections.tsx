import { useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { EditorSection } from '@/components/ui/custom/editor-section';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { CampaignFormValues } from './campaign-editor.form';
import { CampaignEditorConditions } from './CampaignEditorConditions';
import { CampaignEditorReward } from './CampaignEditorReward';

/** The editor's three sections in order. Status and schedule sit in the rail; see CampaignEditorSummaryRail. */
export function CampaignEditorSections() {
  return (
    <div className="flex flex-col gap-4">
      <EditorSection step={1} title={__('Basics', 'yayloyalty')}>
        <CampaignEditorBasics />
      </EditorSection>

      <EditorSection step={2} title={__('Reward', 'yayloyalty')}>
        <CampaignEditorReward />
      </EditorSection>

      <EditorSection step={3} title={__('Conditions', 'yayloyalty')}>
        <CampaignEditorConditions />
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
