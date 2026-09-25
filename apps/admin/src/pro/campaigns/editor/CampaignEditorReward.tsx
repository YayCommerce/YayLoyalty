import { useState } from 'react';
import { ChevronDown, ChevronUp, TriangleAlert } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { usePurchaseCampaignsQuery } from '@/lib/queries/campaign.queries';
import { CampaignTrigger, resolvePurchaseMode } from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import { Field, FieldContent, FieldDescription, FieldLabel, FieldTitle } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { findBuiltinCampaign, triggerLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';

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

/** config_schema()['purchase']; mirrors CAMPAIGN_CONFIG_FIELDS.purchase. */
interface PurchaseConfig {
  points_per_unit?: number;
  mode?: 'rate' | 'multiplier';
  multiplier?: number;
  base?: 'after_discounts' | 'before_discounts';
  include_shipping?: boolean;
  include_tax?: boolean;
  rounding?: 'down' | 'nearest' | 'up';
  min_subtotal?: number;
  exclude_coupon_orders?: boolean;
  excluded_product_ids?: number[];
  excluded_category_ids?: number[];
  max_points_per_order?: number;
  daily_cap?: number;
}

/**
 * Mode choice plus an Advanced block. Multiplier mode inherits only
 * points_per_unit from the default campaign; rounding, base, minimum and
 * exclusions are always this campaign's own, so Advanced renders in both
 * modes.
 */
function RewardFieldsPurchase() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as PurchaseConfig;
  const [advancedOpen, setAdvancedOpen] = useState(false);

  const mode = resolvePurchaseMode(config as Record<string, unknown>);
  const currency = window.yayLoyaltyMeta.wcMeta.currency_data.currency;

  // A multiplier campaign silently stops applying unless the default purchase campaign exists
  // and is active. Asked for in multiplier mode only; rate mode has no use for it.
  const { data: purchaseCampaigns } = usePurchaseCampaignsQuery(mode === 'multiplier');
  const defaultCampaign = findBuiltinCampaign(purchaseCampaigns?.data, 'purchase');
  const defaultPointsPerUnit =
    typeof defaultCampaign?.config.points_per_unit === 'number' ? defaultCampaign.config.points_per_unit : undefined;

  function patchConfig(patch: Partial<PurchaseConfig>) {
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  const multiplier = config.multiplier ?? 1;
  const computedRate = defaultPointsPerUnit !== undefined ? multiplier * defaultPointsPerUnit : undefined;

  return (
    <div className="flex flex-col gap-4">
      {/* Both inputs stay mounted and bound to their own key, so switching modes never clears
          the inactive value; the inactive input is disabled, not hidden. The radio and its label
          get their own row so no control nests inside another control's label. */}
      <RadioGroup
        value={mode}
        onValueChange={(value) => value && patchConfig({ mode: value as PurchaseConfig['mode'] })}
        className="grid gap-3 sm:grid-cols-2"
      >
        <div className="has-data-[state=checked]:border-primary has-data-[state=checked]:bg-primary/5 dark:has-data-[state=checked]:bg-primary/10 flex flex-col gap-2.5 rounded-md border p-4">
          <FieldLabel htmlFor="reward-purchase-mode-multiplier" className="items-center">
            <RadioGroupItem id="reward-purchase-mode-multiplier" value="multiplier" />
            <FieldTitle>{__('Multiply the default rate', 'yayloyalty')}</FieldTitle>
          </FieldLabel>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            decimalScale={2}
            disabled={mode !== 'multiplier'}
            value={config.multiplier ?? 0}
            onValueChange={(value) => patchConfig({ multiplier: value })}
          >
            <NumberInputInput id="reward-purchase-multiplier" />
            <NumberInputUnit unit="×" />
          </NumberInputRoot>
          <FieldDescription>
            {defaultPointsPerUnit !== undefined
              ? sprintf(
                  /* translators: 1: the multiplier, e.g. "2"; 2: the default rate, e.g. "1"; 3: currency code; 4: the resulting rate. */
                  __('%1$s × the default %2$s point(s) per 1 %3$s = %4$s point(s) per 1 %3$s.', 'yayloyalty'),
                  String(multiplier),
                  String(defaultPointsPerUnit),
                  currency,
                  String(computedRate),
                )
              : __('The default purchase campaign has no rate set yet.', 'yayloyalty')}
          </FieldDescription>
        </div>

        <div className="has-data-[state=checked]:border-primary has-data-[state=checked]:bg-primary/5 dark:has-data-[state=checked]:bg-primary/10 flex flex-col gap-2.5 rounded-md border p-4">
          <FieldLabel htmlFor="reward-purchase-mode-rate" className="items-center">
            <RadioGroupItem id="reward-purchase-mode-rate" value="rate" />
            <FieldTitle>{__('Set its own rate', 'yayloyalty')}</FieldTitle>
          </FieldLabel>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            decimalScale={2}
            disabled={mode !== 'rate'}
            value={config.points_per_unit ?? 0}
            onValueChange={(value) => patchConfig({ points_per_unit: value })}
          >
            <NumberInputInput id="reward-purchase-points-per-unit" />
            <NumberInputUnit unit={currency} />
          </NumberInputRoot>
          <FieldDescription>{sprintf(__('Points awarded per 1 %s spent.', 'yayloyalty'), currency)}</FieldDescription>
        </div>
      </RadioGroup>

      {mode === 'multiplier' && (!defaultCampaign || defaultCampaign.status !== 'active') && (
        <Alert variant="outline">
          <TriangleAlert />
          <AlertDescription>
            {__(
              'This campaign will not apply: multiplier mode needs the default purchase campaign to exist and be active.',
              'yayloyalty',
            )}
          </AlertDescription>
        </Alert>
      )}

      <Separator />

      <button
        type="button"
        onClick={() => setAdvancedOpen((open) => !open)}
        className="text-muted-foreground flex w-fit items-center gap-1.5 text-sm font-medium"
        aria-expanded={advancedOpen}
      >
        {advancedOpen ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        {__('Advanced', 'yayloyalty')}
      </button>

      {/* Every remaining purchase key, visible in both modes. */}
      {advancedOpen && (
        <div className="flex flex-col gap-4">
          <Field>
            <FieldLabel>{__('Base amount', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Segmented
                value={config.base ?? 'after_discounts'}
                onValueChange={(value) => value && patchConfig({ base: value as PurchaseConfig['base'] })}
              >
                <SegmentedItem value="after_discounts">{__('After discounts', 'yayloyalty')}</SegmentedItem>
                <SegmentedItem value="before_discounts">{__('Before discounts', 'yayloyalty')}</SegmentedItem>
              </Segmented>
              <FieldDescription>
                {__(
                  'What the points are calculated on. After discounts means a coupon reduces the points too.',
                  'yayloyalty',
                )}
              </FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel>{__('Rounding', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Segmented
                value={config.rounding ?? 'down'}
                onValueChange={(value) => value && patchConfig({ rounding: value as PurchaseConfig['rounding'] })}
              >
                <SegmentedItem value="down">{__('Down', 'yayloyalty')}</SegmentedItem>
                <SegmentedItem value="nearest">{__('Nearest', 'yayloyalty')}</SegmentedItem>
                <SegmentedItem value="up">{__('Up', 'yayloyalty')}</SegmentedItem>
              </Segmented>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-include-tax">{__('Include tax', 'yayloyalty')}</FieldLabel>
            <Switch
              id="reward-purchase-include-tax"
              className="w-11!"
              checked={!!config.include_tax}
              onCheckedChange={(include_tax) => patchConfig({ include_tax })}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-include-shipping">{__('Include shipping', 'yayloyalty')}</FieldLabel>
            <Switch
              id="reward-purchase-include-shipping"
              className="w-11!"
              checked={!!config.include_shipping}
              onCheckedChange={(include_shipping) => patchConfig({ include_shipping })}
            />
          </Field>
          <FieldDescription>
            {__(
              'Not currently used by point calculations — kept so saving this campaign never loses it.',
              'yayloyalty',
            )}
          </FieldDescription>

          <Field>
            <FieldLabel htmlFor="reward-purchase-min-subtotal">{__('Minimum order subtotal', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <NumberInputRoot
                size="small"
                className="w-32"
                min={0}
                decimalScale={2}
                fixedDecimalScale
                value={config.min_subtotal ?? 0}
                onValueChange={(min_subtotal) => patchConfig({ min_subtotal })}
              >
                <NumberInputInput id="reward-purchase-min-subtotal" />
                <NumberInputUnit unit={currency} />
              </NumberInputRoot>
              <FieldDescription>
                {__('Orders below this earn nothing. 0 means every order earns.', 'yayloyalty')}
              </FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-exclude-coupon">
              {__('Exclude orders that used a coupon', 'yayloyalty')}
            </FieldLabel>
            <Switch
              id="reward-purchase-exclude-coupon"
              className="w-11!"
              checked={!!config.exclude_coupon_orders}
              onCheckedChange={(exclude_coupon_orders) => patchConfig({ exclude_coupon_orders })}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-max-points">{__('Maximum points per order', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <NumberInputRoot
                size="small"
                className="w-32"
                min={0}
                value={config.max_points_per_order ?? 0}
                onValueChange={(max_points_per_order) => patchConfig({ max_points_per_order })}
              >
                <NumberInputInput id="reward-purchase-max-points" />
                <NumberInputUnit unit={__('points', 'yayloyalty')} />
              </NumberInputRoot>
              <FieldDescription>{__('0 means no per-order cap.', 'yayloyalty')}</FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-daily-cap">{__('Daily cap', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <NumberInputRoot
                size="small"
                className="w-32"
                min={0}
                value={config.daily_cap ?? 0}
                onValueChange={(daily_cap) => patchConfig({ daily_cap })}
              >
                <NumberInputInput id="reward-purchase-daily-cap" />
                <NumberInputUnit unit={__('points', 'yayloyalty')} />
              </NumberInputRoot>
              <FieldDescription>{__('0 means no daily cap.', 'yayloyalty')}</FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel>{__('Excluded products', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <LookupMultiSelect
                entity="products"
                value={config.excluded_product_ids ?? []}
                onChange={(next) => patchConfig({ excluded_product_ids: next })}
                placeholder={__('Search products', 'yayloyalty')}
              />
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel>{__('Excluded categories', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <LookupMultiSelect
                entity="categories"
                value={config.excluded_category_ids ?? []}
                onChange={(next) => patchConfig({ excluded_category_ids: next })}
                placeholder={__('Search categories', 'yayloyalty')}
              />
            </FieldContent>
          </Field>
        </div>
      )}
    </div>
  );
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
  daily_cap?: number;
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
          <NumberInputRoot
            size="small"
            className="w-32"
            min={0}
            value={config.daily_cap ?? 0}
            onValueChange={(daily_cap) => patchConfig({ daily_cap })}
          >
            <NumberInputInput id="reward-review-daily-cap" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
          <FieldDescription>{__('0 means no daily cap.', 'yayloyalty')}</FieldDescription>
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
