import { useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { formatPoints } from '@/lib/helpers/points.helper';
import { Input } from '@/components/ui/input';
import { POINTS_LABEL_MAX_LENGTH } from '@/pages/settings/general-settings.form';
import { SAMPLE_BALANCE, type WizardStepProps } from './setup-wizard.form';
import { WizardPreview, WizardSection, WizardStepHeading } from './WizardStepParts';

/** Step 1: what the points are called. */
export function NamePointsStep({ form }: WizardStepProps) {
  const plural = useWatch({ control: form.control, name: 'pointsLabelPlural' });

  return (
    <>
      <WizardStepHeading
        title={__('What should we call your points?', 'yayloyalty')}
        description={__(
          'One quick answer. Nothing is published until the last step, and every choice can be changed afterwards.',
          'yayloyalty',
        )}
      />
      <WizardSection>
        <div className="flex flex-col gap-2.5">
          <div className="text-sm font-semibold">{__('What are they called?', 'yayloyalty')}</div>
          <div className="flex flex-wrap items-end gap-4">
            <label className="flex flex-col gap-1.5">
              <span className="text-muted-foreground text-xs">{__('One of them', 'yayloyalty')}</span>
              <Input className="w-44" maxLength={POINTS_LABEL_MAX_LENGTH} {...form.register('pointsLabelSingular')} />
            </label>
            <label className="flex flex-col gap-1.5">
              <span className="text-muted-foreground text-xs">{__('More than one', 'yayloyalty')}</span>
              <Input className="w-44" maxLength={POINTS_LABEL_MAX_LENGTH} {...form.register('pointsLabelPlural')} />
            </label>
          </div>
        </div>
        <WizardPreview>
          {/* translators: 1: a sample balance, formatted; 2: the plural points label. */}
          {sprintf(__('You have %1$s %2$s', 'yayloyalty'), formatPoints(SAMPLE_BALANCE), plural)}
        </WizardPreview>
      </WizardSection>
    </>
  );
}
