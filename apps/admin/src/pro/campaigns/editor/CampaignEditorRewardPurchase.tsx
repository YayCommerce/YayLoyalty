import { useState } from 'react';
import { ChevronDown, ChevronUp, TriangleAlert } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { ROUNDING, RoundingMode } from '@/lib/points-math/points-math';
import { usePurchaseCampaignsQuery } from '@/lib/queries/campaign.queries';
import { findBuiltinCampaign, resolvePurchaseMode, resolvePurchaseMultiplier } from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import { Field, FieldContent, FieldDescription, FieldLabel, FieldTitle } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { givesLabel, isBuiltinCampaignActive } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';
import { defaultPurchaseRate, multipliedRate, purchaseAdvancedPhrases } from './campaign-reward.copy';
import { storeCurrency } from '@/lib/helpers/format.helper';

/** config_schema()['purchase']; mirrors CAMPAIGN_CONFIG_FIELDS.purchase. */
interface PurchaseConfig {
  points_per_unit?: number;
  mode?: 'rate' | 'multiplier';
  multiplier?: number;
  base?: 'after_discounts' | 'before_discounts';
  include_shipping?: boolean;
  include_tax?: boolean;
  rounding?: RoundingMode;
  min_subtotal?: number;
  exclude_coupon_orders?: boolean;
  excluded_product_ids?: number[];
  excluded_category_ids?: number[];
  max_points_per_order?: number;
  daily_cap?: number;
}

type PatchPurchaseConfig = (patch: Partial<PurchaseConfig>) => void;

interface PurchaseFieldsProps {
  config: PurchaseConfig;
  patchConfig: PatchPurchaseConfig;
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
export function RewardFieldsPurchase() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  // Every multiplier multiplies this campaign's rate, so it only ever sets its own.
  const isDefaultCampaign = useCampaignEditorContext().campaign.is_builtin;
  const config = watch('config') as PurchaseConfig;
  const mode = resolvePurchaseMode(config as Record<string, unknown>);

  // A multiplier campaign silently stops applying unless the default purchase campaign exists
  // and is active. Asked for in multiplier mode only; rate mode has no use for it.
  const { data: purchaseCampaigns, isSuccess: hasLoadedPurchaseCampaigns } = usePurchaseCampaignsQuery(
    mode === 'multiplier',
  );
  const defaultCampaign = findBuiltinCampaign(purchaseCampaigns?.data, 'purchase');

  function patchConfig(patch: Partial<PurchaseConfig>) {
    // Spread the stored config: keys with no control here, such as include_shipping, must survive a save.
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  const isMultiplierStranded =
    mode === 'multiplier' &&
    !isDefaultCampaign &&
    hasLoadedPurchaseCampaigns &&
    !isBuiltinCampaignActive(defaultCampaign);

  return (
    <div className="flex flex-col gap-4">
      {isDefaultCampaign ? (
        <Field>
          <FieldLabel htmlFor="reward-purchase-points-per-unit">{__('Rate', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <PurchaseRateInput config={config} patchConfig={patchConfig} disabled={false} />
            <PurchaseRateDescription />
          </FieldContent>
        </Field>
      ) : (
        <PurchaseModeChoice
          config={config}
          patchConfig={patchConfig}
          // The default's rate is only fetched in multiplier mode and only lent when that campaign is active.
          defaultPointsPerUnit={hasLoadedPurchaseCampaigns ? defaultPurchaseRate(defaultCampaign) : undefined}
        />
      )}

      {isMultiplierStranded && (
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

      <PurchaseAdvanced config={config} patchConfig={patchConfig} />
    </div>
  );
}

function PurchaseRateInput({ config, patchConfig, disabled }: PurchaseFieldsProps & { disabled: boolean }) {
  return (
    <NumberInputRoot
      size="small"
      className="w-36"
      min={SMALLEST_PURCHASE_FACTOR}
      decimalScale={2}
      disabled={disabled}
      value={config.points_per_unit ?? 0}
      onValueChange={(value) => patchConfig({ points_per_unit: value })}
    >
      <NumberInputInput id="reward-purchase-points-per-unit" />
      <NumberInputUnit
        unit={sprintf(
          /* translators: %s: the store currency code, e.g. "USD". */
          __('pts / %s', 'yayloyalty'),
          storeCurrency(),
        )}
      />
    </NumberInputRoot>
  );
}

function PurchaseRateDescription() {
  return (
    <FieldDescription>
      {sprintf(
        /* translators: %s: the store currency code, e.g. "USD". */
        __('Points for every 1 %s of the amount counted below.', 'yayloyalty'),
        storeCurrency(),
      )}
    </FieldDescription>
  );
}

/**
 * Multiply the default rate, or set this campaign's own. Both inputs stay
 * mounted and bound to their own key, so switching modes never clears the
 * inactive value; the inactive input is disabled, not hidden. The radio and
 * its label get their own row so no control nests inside another control's
 * label.
 */
function PurchaseModeChoice({
  config,
  patchConfig,
  defaultPointsPerUnit,
}: PurchaseFieldsProps & {
  /** undefined while loading, or when the default is missing, not active or has no rate. */
  defaultPointsPerUnit: number | undefined;
}) {
  const mode = resolvePurchaseMode(config as Record<string, unknown>);
  const multiplier = resolvePurchaseMultiplier(config as Record<string, unknown>);

  return (
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
        {/* Without a rate to show, say what the option does instead of guessing at one. The
            warning below the choice explains a paused or missing default. */}
        <FieldDescription>
          {mode === 'multiplier' && defaultPointsPerUnit !== undefined
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
        <PurchaseRateInput config={config} patchConfig={patchConfig} disabled={mode !== 'rate'} />
        <PurchaseRateDescription />
      </div>
    </RadioGroup>
  );
}

/** The collapsible block holding every remaining purchase key, visible in both modes. */
function PurchaseAdvanced({ config, patchConfig }: PurchaseFieldsProps) {
  const advancedPhrases = purchaseAdvancedPhrases(config as Record<string, unknown>);
  // Opens by itself only on mount: a campaign reopened with caps or exclusions shows them, and
  // typing a value later never yanks the block open or shut.
  const [advancedOpen, setAdvancedOpen] = useState(() => advancedPhrases.length > 0);

  return (
    <>
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

      {advancedOpen && (
        <div className="flex flex-col gap-4">
          <PurchaseCountingFields config={config} patchConfig={patchConfig} />
          <PurchaseLimitFields config={config} patchConfig={patchConfig} />
        </div>
      )}
    </>
  );
}

/** What amount the rate applies to, and which orders count at all. */
function PurchaseCountingFields({ config, patchConfig }: PurchaseFieldsProps) {
  return (
    <>
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
            value={config.rounding ?? ROUNDING.DOWN}
            onValueChange={(value) => value && patchConfig({ rounding: value as RoundingMode })}
          >
            <SegmentedItem value={ROUNDING.DOWN}>{__('Down', 'yayloyalty')}</SegmentedItem>
            <SegmentedItem value={ROUNDING.NEAREST}>{__('Nearest', 'yayloyalty')}</SegmentedItem>
            <SegmentedItem value={ROUNDING.UP}>{__('Up', 'yayloyalty')}</SegmentedItem>
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
            <NumberInputUnit unit={storeCurrency()} />
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
    </>
  );
}

/** Caps on what one order or one day can earn, and lines left out of the amount. */
function PurchaseLimitFields({ config, patchConfig }: PurchaseFieldsProps) {
  return (
    <>
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
    </>
  );
}
