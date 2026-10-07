import { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { storeCurrency } from '@/lib/helpers/format.helper';
import { ROUNDING, RoundingMode } from '@/lib/points-math/points-math';
import { OptionalCountInput } from '@/components/ui/custom/amount-inputs';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
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

/** The server refuses a rate of 0 or below; the input keeps 2 decimals, so this is its first step above 0. */
const SMALLEST_PURCHASE_RATE = 0.01;

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
      <Field>
        <FieldLabel htmlFor="reward-purchase-points-per-unit">{__('Rate', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className="w-36"
            min={SMALLEST_PURCHASE_RATE}
            decimalScale={2}
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
          <FieldDescription>
            {sprintf(
              /* translators: %s: the store currency code, e.g. "USD". */
              __('Points for every 1 %s of the order amount. Advanced sets what counts.', 'yayloyalty'),
              storeCurrency(),
            )}
          </FieldDescription>
        </FieldContent>
      </Field>

      <Separator />

      <PurchaseAdvanced config={config} patchConfig={patchConfig} />
    </div>
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
          <OptionalCountInput
            id="reward-purchase-max-points"
            className="w-32"
            value={config.max_points_per_order ?? null}
            unit={__('points', 'yayloyalty')}
            onValueChange={(max_points_per_order) => patchConfig({ max_points_per_order })}
          />
          <FieldDescription>{__('Applied after rounding. Leave empty for no cap.', 'yayloyalty')}</FieldDescription>
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-purchase-daily-cap">{__('Daily cap', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <OptionalCountInput
            id="reward-purchase-daily-cap"
            className="w-32"
            value={config.daily_cap ?? null}
            unit={__('points', 'yayloyalty')}
            onValueChange={(daily_cap) => patchConfig({ daily_cap })}
          />
          <FieldDescription>
            {__('Most points one customer can earn from this campaign in a day. Leave empty for no cap.', 'yayloyalty')}
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
