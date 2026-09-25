import { useState } from 'react';
import { ChevronDown, ChevronUp, TriangleAlert } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { usePurchaseCampaignsQuery } from '@/lib/queries/campaign.queries';
import { CampaignTrigger, resolvePurchaseMode, resolvePurchaseMultiplier } from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import { Field, FieldContent, FieldDescription, FieldLabel, FieldTitle } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { findBuiltinCampaign, givesLabel, triggerLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';
import {
  defaultPurchaseRate,
  isDefaultPurchaseActive,
  multipliedRate,
  purchaseAdvancedPhrases,
} from './campaign-reward.copy';

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

/** The server refuses a rate or multiplier of 0 or below; the inputs keep 2 decimals, so this is their first step above 0. */
const SMALLEST_PURCHASE_FACTOR = 0.01;

/**
 * The default campaign's own rate, or a rate/multiplier choice for any other
 * purchase campaign, plus an Advanced block. Multiplier mode inherits only
 * points_per_unit from the default campaign; rounding, base, minimum and
 * exclusions are always this campaign's own, so Advanced renders in both
 * modes.
 */
function RewardFieldsPurchase() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  // Every multiplier multiplies this campaign's rate, so it only ever sets its own.
  const isDefaultCampaign = useCampaignEditorContext().campaign.is_builtin;
  const config = watch('config') as PurchaseConfig;
  const mode = resolvePurchaseMode(config as Record<string, unknown>);
  const currency = window.yayLoyaltyMeta.wcMeta.currency_data.currency;
  const advancedPhrases = purchaseAdvancedPhrases(config as Record<string, unknown>);
  // Opens by itself only on mount: a campaign reopened with caps or exclusions shows them, and
  // typing a value later never yanks the block open or shut.
  const [advancedOpen, setAdvancedOpen] = useState(() => advancedPhrases.length > 0);

  // A multiplier campaign silently stops applying unless the default purchase campaign exists
  // and is active. Asked for in multiplier mode only; rate mode has no use for it.
  const { data: purchaseCampaigns, isSuccess: hasLoadedPurchaseCampaigns } = usePurchaseCampaignsQuery(
    mode === 'multiplier',
  );
  const defaultCampaign = findBuiltinCampaign(purchaseCampaigns?.data, 'purchase');
  const defaultPointsPerUnit = defaultPurchaseRate(defaultCampaign);

  function patchConfig(patch: Partial<PurchaseConfig>) {
    // Spread the stored config: keys with no control here, such as include_shipping, must survive a save.
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  const multiplier = resolvePurchaseMultiplier(config as Record<string, unknown>);

  const rateInput = (
    <NumberInputRoot
      size="small"
      className="w-36"
      min={SMALLEST_PURCHASE_FACTOR}
      decimalScale={2}
      disabled={mode !== 'rate' && !isDefaultCampaign}
      value={config.points_per_unit ?? 0}
      onValueChange={(value) => patchConfig({ points_per_unit: value })}
    >
      <NumberInputInput id="reward-purchase-points-per-unit" />
      <NumberInputUnit
        unit={sprintf(
          /* translators: %s: the store currency code, e.g. "USD". */ __('pts / %s', 'yayloyalty'),
          currency,
        )}
      />
    </NumberInputRoot>
  );
  const rateDescription = (
    <FieldDescription>
      {sprintf(
        /* translators: %s: the store currency code, e.g. "USD". */
        __('Points for every 1 %s of the amount counted below.', 'yayloyalty'),
        currency,
      )}
    </FieldDescription>
  );

  return (
    <div className="flex flex-col gap-4">
      {isDefaultCampaign && (
        <Field>
          <FieldLabel htmlFor="reward-purchase-points-per-unit">{__('Rate', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            {rateInput}
            {rateDescription}
          </FieldContent>
        </Field>
      )}

      {/* Both inputs stay mounted and bound to their own key, so switching modes never clears
          the inactive value; the inactive input is disabled, not hidden. The radio and its label
          get their own row so no control nests inside another control's label. */}
      {!isDefaultCampaign && (
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
              min={SMALLEST_PURCHASE_FACTOR}
              decimalScale={2}
              disabled={mode !== 'multiplier'}
              value={multiplier}
              onValueChange={(value) => patchConfig({ multiplier: value })}
            >
              <NumberInputInput id="reward-purchase-multiplier" autoComplete="off" />
              <NumberInputUnit unit="×" />
            </NumberInputRoot>
            {/* The default's rate is only fetched in multiplier mode and only lent when that campaign is
                active; otherwise say what the option does instead of guessing at a rate. The warning
                below explains a paused or missing default. */}
            <FieldDescription>
              {mode === 'multiplier' && hasLoadedPurchaseCampaigns && defaultPointsPerUnit !== undefined
                ? sprintf(
                    /* translators: 1: the multiplier, e.g. "2"; 2: the default rate, e.g. "1 pt per $1.00"; 3: the resulting rate, e.g. "2 pts per $1.00". */
                    __('%1$s × %2$s = %3$s.', 'yayloyalty'),
                    String(multiplier),
                    givesLabel('purchase', { points_per_unit: defaultPointsPerUnit }),
                    givesLabel('purchase', { points_per_unit: multipliedRate(multiplier, defaultPointsPerUnit) }),
                  )
                : __('Multiplies whatever the default purchase campaign awards.', 'yayloyalty')}
            </FieldDescription>
          </div>

          <div className="has-data-[state=checked]:border-primary has-data-[state=checked]:bg-primary/5 dark:has-data-[state=checked]:bg-primary/10 flex flex-col gap-2.5 rounded-md border p-4">
            <FieldLabel htmlFor="reward-purchase-mode-rate" className="items-center">
              <RadioGroupItem id="reward-purchase-mode-rate" value="rate" />
              <FieldTitle>{__('Set its own rate', 'yayloyalty')}</FieldTitle>
            </FieldLabel>
            {rateInput}
            {rateDescription}
          </div>
        </RadioGroup>
      )}

      {mode === 'multiplier' &&
        !isDefaultCampaign &&
        hasLoadedPurchaseCampaigns &&
        !isDefaultPurchaseActive(defaultCampaign) && (
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
        {!advancedOpen && advancedPhrases.length > 0 && (
          <span className="font-normal">· {advancedPhrases.join(' · ')}</span>
        )}
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
              <FieldDescription>{__('How a fractional result becomes whole points.', 'yayloyalty')}</FieldDescription>
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
            <FieldDescription>{__('Adds each line’s tax to the amount counted.', 'yayloyalty')}</FieldDescription>
          </Field>

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
                {__(
                  'This campaign does not apply to orders whose subtotal, before discounts, is under this amount. 0 means no minimum.',
                  'yayloyalty',
                )}
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
            <FieldDescription>
              {__(
                'This campaign does not apply to orders that used a coupon. Points redeemed through YayLoyalty don’t count as a coupon.',
                'yayloyalty',
              )}
            </FieldDescription>
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
              <FieldDescription>{__('Applied after rounding. 0 means no cap.', 'yayloyalty')}</FieldDescription>
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
              <FieldDescription>
                {__('Most points one customer can earn from this campaign in a day. 0 means no cap.', 'yayloyalty')}
              </FieldDescription>
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
              <FieldDescription>{__('These lines don’t count toward the amount.', 'yayloyalty')}</FieldDescription>
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
              <FieldDescription>{__('These lines don’t count toward the amount.', 'yayloyalty')}</FieldDescription>
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
