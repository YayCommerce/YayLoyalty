import { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { currencyDecimals, parseWPCurrency, storeCurrency, storeCurrencySymbol } from '@/lib/helpers/format.helper';
import { rateFromSpend, spendPairFromRate } from '@/lib/helpers/points.helper';
import { ROUNDING, RoundingMode } from '@/lib/points-math/points-math';
import { OptionalCountInput } from '@/components/ui/custom/amount-inputs';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import {
  Field,
  FieldContent,
  FieldDescription,
  FieldLabel,
  FieldLegend,
  FieldSeparator,
  FieldSet,
} from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { CampaignFormValues } from './campaign-editor.form';
import { purchaseAdvancedPhrases } from './campaign-reward.copy';

/** config_schema()['purchase']; mirrors CAMPAIGN_CONFIG_FIELDS.purchase. */
interface PurchaseConfig {
  points_per_unit?: number;
  base?: 'after_discounts' | 'before_discounts';
  include_shipping?: boolean;
  include_tax?: boolean;
  rounding?: RoundingMode;
  min_subtotal?: number;
  exclude_coupon_orders?: boolean;
  excluded_product_ids?: number[];
  excluded_category_ids?: number[];
  max_points_per_order?: number | null;
  daily_cap?: number | null;
}

type PatchPurchaseConfig = (patch: Partial<PurchaseConfig>) => void;

interface PurchaseFieldsProps {
  config: PurchaseConfig;
  patchConfig: PatchPurchaseConfig;
}

/** The campaign's rate, then the Advanced block. */
export function RewardFieldsPurchase() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as PurchaseConfig;

  function patchConfig(patch: Partial<PurchaseConfig>) {
    // Spread the stored config: keys with no control here, such as include_shipping, must survive a save.
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  return (
    <div className="flex flex-col gap-4">
      <PurchaseRateField config={config} patchConfig={patchConfig} />

      <Separator />

      <PurchaseAdvanced config={config} patchConfig={patchConfig} />
    </div>
  );
}

/** "Spend X to receive Y"; only the rate Y ÷ X is stored, as points_per_unit. */
function PurchaseRateField({ config, patchConfig }: PurchaseFieldsProps) {
  // Seeded once: re-deriving from the stored rate while typing would rewrite the other input.
  const [pair, setPair] = useState(() => spendPairFromRate(config.points_per_unit ?? 1));
  const smallestSpend = 10 ** -currencyDecimals();

  function change(next: { spend: number; points: number }) {
    setPair(next);
    const rate = next.points > 0 ? rateFromSpend(next.spend, next.points) : null;

    if (rate !== null) {
      patchConfig({ points_per_unit: rate });
    }
  }

  return (
    <Field>
      <FieldLabel htmlFor="reward-purchase-spend">{__('Rate', 'yayloyalty')}</FieldLabel>
      <FieldContent>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span>{__('Spend', 'yayloyalty')}</span>
          <NumberInputRoot
            size="small"
            className="w-36"
            min={smallestSpend}
            decimalScale={currencyDecimals()}
            value={pair.spend}
            onValueChange={(spend) => change({ ...pair, spend })}
          >
            <NumberInputInput id="reward-purchase-spend" />
            <NumberInputUnit unit={storeCurrencySymbol()} />
          </NumberInputRoot>
          <span>{__('to receive', 'yayloyalty')}</span>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={1}
            value={pair.points}
            onValueChange={(points) => change({ ...pair, points })}
          >
            <NumberInputInput id="reward-purchase-points" aria-label={__('Points received', 'yayloyalty')} />
            <NumberInputUnit unit={__('pts', 'yayloyalty')} />
          </NumberInputRoot>
        </div>
        <FieldDescription>
          {sprintf(
            /* translators: 1: points per one currency unit, e.g. "0.5"; 2: one unit of the store currency, e.g. "$1.00". */
            __('= %1$s pts per %2$s. Advanced sets which part of the order counts.', 'yayloyalty'),
            (config.points_per_unit ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 }),
            parseWPCurrency(1),
          )}
        </FieldDescription>
      </FieldContent>
    </Field>
  );
}

/** The collapsible panel holding every remaining purchase key, grouped in the order the engine applies them. */
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
        <div className="flex flex-col gap-5 rounded-lg border p-4">
          <PurchaseEligibilityFields config={config} patchConfig={patchConfig} />
          <FieldSeparator />
          <PurchaseAmountFields config={config} patchConfig={patchConfig} />
          <FieldSeparator />
          <PurchaseRoundingFields config={config} patchConfig={patchConfig} />
        </div>
      )}
    </>
  );
}

/** One headed group inside the Advanced panel. */
function PurchaseGroup({ legend, children }: { legend: string; children: React.ReactNode }) {
  return (
    <FieldSet className="gap-4">
      <FieldLegend variant="label">{legend}</FieldLegend>
      {children}
    </FieldSet>
  );
}

/** Which orders the campaign applies to at all. */
function PurchaseEligibilityFields({ config, patchConfig }: PurchaseFieldsProps) {
  return (
    <PurchaseGroup legend={__('Eligible orders', 'yayloyalty')}>
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
            {__('Orders below this, before discounts, earn nothing. 0 means no minimum.', 'yayloyalty')}
          </FieldDescription>
        </FieldContent>
      </Field>

      <Field orientation="horizontal">
        <FieldContent>
          <FieldLabel htmlFor="reward-purchase-exclude-coupon">
            {__('Exclude orders that used a coupon', 'yayloyalty')}
          </FieldLabel>
          <FieldDescription>
            {__('Redeeming YayLoyalty points doesn’t count as a coupon.', 'yayloyalty')}
          </FieldDescription>
        </FieldContent>
        <Switch
          id="reward-purchase-exclude-coupon"
          className="w-11!"
          checked={!!config.exclude_coupon_orders}
          onCheckedChange={(exclude_coupon_orders) => patchConfig({ exclude_coupon_orders })}
        />
      </Field>
    </PurchaseGroup>
  );
}

/** What part of an eligible order the rate applies to. */
function PurchaseAmountFields({ config, patchConfig }: PurchaseFieldsProps) {
  return (
    <PurchaseGroup legend={__('Amount counted', 'yayloyalty')}>
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
            {__('After discounts means a coupon reduces the points too.', 'yayloyalty')}
          </FieldDescription>
        </FieldContent>
      </Field>

      <Field orientation="horizontal">
        <FieldLabel htmlFor="reward-purchase-include-tax">{__('Include tax', 'yayloyalty')}</FieldLabel>
        <Switch
          id="reward-purchase-include-tax"
          className="w-11!"
          checked={!!config.include_tax}
          onCheckedChange={(include_tax) => patchConfig({ include_tax })}
        />
      </Field>

      <Field>
        <FieldLabel>{__('Excluded products', 'yayloyalty')}</FieldLabel>
        <LookupMultiSelect
          entity="products"
          value={config.excluded_product_ids ?? []}
          onChange={(next) => patchConfig({ excluded_product_ids: next })}
          placeholder={__('Search products', 'yayloyalty')}
        />
      </Field>

      <Field>
        <FieldLabel>{__('Excluded categories', 'yayloyalty')}</FieldLabel>
        <LookupMultiSelect
          entity="categories"
          value={config.excluded_category_ids ?? []}
          onChange={(next) => patchConfig({ excluded_category_ids: next })}
          placeholder={__('Search categories', 'yayloyalty')}
        />
      </Field>
    </PurchaseGroup>
  );
}

/** How the result becomes whole points, and the caps applied after that. */
function PurchaseRoundingFields({ config, patchConfig }: PurchaseFieldsProps) {
  return (
    <PurchaseGroup legend={__('Rounding and caps', 'yayloyalty')}>
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
        </FieldContent>
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field>
          <FieldLabel htmlFor="reward-purchase-max-points">{__('Maximum points per order', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <OptionalCountInput
              id="reward-purchase-max-points"
              className="w-40"
              value={config.max_points_per_order ?? null}
              unit={__('points', 'yayloyalty')}
              onValueChange={(max_points_per_order) => patchConfig({ max_points_per_order })}
            />
            <FieldDescription>{__('Applied after rounding.', 'yayloyalty')}</FieldDescription>
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="reward-purchase-daily-cap">{__('Daily cap', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <OptionalCountInput
              id="reward-purchase-daily-cap"
              className="w-40"
              value={config.daily_cap ?? null}
              unit={__('points', 'yayloyalty')}
              onValueChange={(daily_cap) => patchConfig({ daily_cap })}
            />
            <FieldDescription>{__('Per customer.', 'yayloyalty')}</FieldDescription>
          </FieldContent>
        </Field>
      </div>
    </PurchaseGroup>
  );
}
