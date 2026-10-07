import { useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { CampaignTrigger } from '@/lib/schema/campaign.schema';
import { OptionalCountInput } from '@/components/ui/custom/amount-inputs';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Switch } from '@/components/ui/switch';
import { triggerLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';
import { RewardFieldsPurchase } from './CampaignEditorRewardPurchase';

/** Reward section: the per-trigger field maps behind the editor's third card. */

/** Dispatches on the effective trigger (context's `trigger`, not campaign.trigger — see CampaignEditorContextValue); anything without a field map falls back to RewardFieldsUnknown. */
export function CampaignEditorReward() {
  const { trigger } = useCampaignEditorContext();

  switch (trigger) {
    case 'purchase':
      return <RewardFieldsPurchase />;
    case 'signup':
      return <RewardFieldsSignup />;
    case 'review':
      return <RewardFieldsReview />;
    default:
      return <RewardFieldsUnknown trigger={trigger} />;
  }
}

/** config_schema()['signup']. */
interface SignupConfig {
  points?: number;
}

function RewardFieldsSignup() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as SignupConfig;

  return (
    <Field>
      <FieldLabel htmlFor="reward-signup-points">{__('Award', 'yayloyalty')}</FieldLabel>
      <FieldContent>
        <NumberInputRoot
          size="small"
          className="w-28"
          min={0}
          value={config.points ?? 0}
          onValueChange={(points) =>
            setValue('config', { ...config, points }, { shouldDirty: true, shouldValidate: true })
          }
        >
          <NumberInputInput id="reward-signup-points" />
          <NumberInputUnit unit={__('points', 'yayloyalty')} />
        </NumberInputRoot>
        <FieldDescription>
          {__('Awarded once, the first time a customer creates an account.', 'yayloyalty')}
        </FieldDescription>
      </FieldContent>
    </Field>
  );
}

/** config_schema()['review']. */
interface ReviewConfig {
  points?: number;
  require_verified?: boolean;
  one_per_product?: boolean;
  daily_cap?: number | null;
  media_bonus_points?: number;
}

function RewardFieldsReview() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as ReviewConfig;

  function patchConfig(patch: Partial<ReviewConfig>) {
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  return (
    <>
      <Field>
        <FieldLabel htmlFor="reward-review-points">{__('Award', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            value={config.points ?? 0}
            onValueChange={(points) => patchConfig({ points })}
          >
            <NumberInputInput id="reward-review-points" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
          <FieldDescription>{__('Per approved product review.', 'yayloyalty')}</FieldDescription>
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-review-media-bonus">{__('Media bonus', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            value={config.media_bonus_points ?? 0}
            onValueChange={(media_bonus_points) => patchConfig({ media_bonus_points })}
          >
            <NumberInputInput id="reward-review-media-bonus" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
          <FieldDescription>
            {__('Extra points when the review includes a photo or video.', 'yayloyalty')}
          </FieldDescription>
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-review-verified">{__('Verified purchases only', 'yayloyalty')}</FieldLabel>
        <Switch
          id="reward-review-verified"
          className="w-11!"
          checked={!!config.require_verified}
          onCheckedChange={(require_verified) => patchConfig({ require_verified })}
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-review-one-per-product">{__('One review per product', 'yayloyalty')}</FieldLabel>
        <Switch
          id="reward-review-one-per-product"
          className="w-11!"
          checked={!!config.one_per_product}
          onCheckedChange={(one_per_product) => patchConfig({ one_per_product })}
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-review-daily-cap">{__('Daily cap', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <OptionalCountInput
            id="reward-review-daily-cap"
            className="w-32"
            value={config.daily_cap ?? null}
            unit={__('points', 'yayloyalty')}
            onValueChange={(daily_cap) => patchConfig({ daily_cap })}
          />
          <FieldDescription>{__('Leave empty for no daily cap.', 'yayloyalty')}</FieldDescription>
        </FieldContent>
      </Field>
    </>
  );
}

/**
 * Fallback for a trigger with no field map (every Pro trigger). Binds
 * nothing back to config: the seeded clone must round-trip untouched, or
 * this would erase on the client what the server preserves for a downgrade.
 */
function RewardFieldsUnknown({ trigger }: { trigger: CampaignTrigger }) {
  const { watch } = useFormContext<CampaignFormValues>();
  const config = watch('config');
  const entries = Object.entries(config ?? {});

  return (
    <div className="flex flex-col gap-3">
      <FieldDescription>
        {sprintf(
          /* translators: %s: the trigger's display label, e.g. "Birthday". */
          __(
            'This build has no editor for the "%s" trigger yet. Its settings are shown below for reference and are saved back unchanged.',
            'yayloyalty',
          ),
          triggerLabel(trigger),
        )}
      </FieldDescription>

      {entries.length === 0 ? (
        <p className="text-muted-foreground text-sm">{__('No settings stored.', 'yayloyalty')}</p>
      ) : (
        // Plain text nodes only; these values are campaign-supplied and untyped.
        <dl className="flex flex-col gap-1.5 text-sm">
          {entries.map(([key, value]) => (
            <div key={key} className="flex items-baseline justify-between gap-3">
              <dt className="text-muted-foreground font-mono text-xs">{key}</dt>
              <dd className="text-right font-mono text-xs break-all">{formatUnknownConfigValue(value)}</dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  );
}

function formatUnknownConfigValue(value: unknown): string {
  if (value === null || value === undefined) {
    return '—';
  }

  if (typeof value === 'object') {
    return JSON.stringify(value);
  }

  return String(value);
}
