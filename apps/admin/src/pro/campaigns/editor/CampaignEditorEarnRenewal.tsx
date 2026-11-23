import { useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import type { RenewalConfig } from '@/lib/schema/campaign.schema';
import { AMOUNT_INPUT_WIDTH } from '@/components/ui/custom/amount-inputs';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Switch } from '@/components/ui/switch';
import { CampaignFormValues } from './campaign-editor.form';

/** Reward fields for a subscription renewal campaign: flat points per renewal order, on top of or instead of order points. */
export function CampaignEditorEarnRenewal() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as RenewalConfig;

  function patchConfig(patch: Partial<RenewalConfig>) {
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  return (
    <>
      <Field>
        <FieldLabel htmlFor="reward-renewal-points">{__('Award', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className={AMOUNT_INPUT_WIDTH}
            min={0}
            value={config.points ?? 0}
            onValueChange={(points) => patchConfig({ points })}
          >
            <NumberInputInput id="reward-renewal-points" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
          <FieldDescription>{__('Per renewal order, once it is completed.', 'yayloyalty')}</FieldDescription>
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-renewal-replace">{__('Instead of order points', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Switch
            id="reward-renewal-replace"
            className="w-11!"
            checked={!!config.replace_purchase}
            onCheckedChange={(replace_purchase) => patchConfig({ replace_purchase })}
          />
          <FieldDescription>
            {__('Off: the renewal earns these points on top of its usual order points.', 'yayloyalty')}
          </FieldDescription>
        </FieldContent>
      </Field>
    </>
  );
}
