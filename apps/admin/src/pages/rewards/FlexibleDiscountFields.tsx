import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { formatPoints, spendPairFromRate } from '@/lib/helpers/points.helper';
import { MIN_BELOW_INCREMENT_MESSAGE, RedemptionMode, RewardConfigOf } from '@/lib/schema/reward.schema';
import { AMOUNT_INPUT_WIDTH, OptionalCountInput, PointsInput } from '@/components/ui/custom/amount-inputs';
import { ConfigField } from '@/components/ui/custom/config-field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

export type FlexibleDiscountConfig = RewardConfigOf<'flexible_discount'>;

/** Decimals the rate input keeps: enough for 1 pt per 1,000,000 of a 0-decimal currency. */
const RATE_DECIMALS = 6;

/**
 * The built-in flexible discount's settings (1g), shared by Lite's inline
 * card and Pro's reward editor so both edit the same fields the same way.
 */
export function FlexibleDiscountFields({
  value,
  onChange,
}: {
  value: FlexibleDiscountConfig;
  onChange: (next: Partial<FlexibleDiscountConfig>) => void;
}) {
  const currency = storeCurrency();

  return (
    <>
      <ConfigField label={__('Conversion rate', 'yayloyalty')}>
        <div className="flex flex-wrap items-center gap-2">
          <NumberInputRoot
            size="small"
            className={AMOUNT_INPUT_WIDTH}
            min={10 ** -RATE_DECIMALS}
            decimalScale={RATE_DECIMALS}
            value={value.points_per_currency_unit}
            onValueChange={(points_per_currency_unit) => onChange({ points_per_currency_unit })}
          >
            <NumberInputInput />
            <NumberInputUnit unit={_n('point', 'points', value.points_per_currency_unit, 'yayloyalty')} />
          </NumberInputRoot>
          <span className="text-muted-foreground text-sm">
            {sprintf(
              /* translators: 1: one unit of the store currency, formatted; 2: the site's currency code, e.g. USD. */
              __('= %1$s (%2$s) off', 'yayloyalty'),
              parseWPCurrency(1),
              currency,
            )}
          </span>
        </div>
      </ConfigField>

      {value.points_per_currency_unit > 0 && (
        <div className="bg-muted flex items-center gap-3 rounded-md border p-3.5">
          <span className="text-sm font-semibold">{pointsWorth(value.points_per_currency_unit)}</span>
        </div>
      )}

      <ConfigField
        label={__('Redemption increment', 'yayloyalty')}
        description={__('Points are redeemed in multiples of this.', 'yayloyalty')}
      >
        <PointsInput value={value.increment} min={1} onValueChange={(increment) => onChange({ increment })} />
      </ConfigField>

      <ConfigField
        label={__('Minimum points per redemption', 'yayloyalty')}
        description={__('At least the redemption increment.', 'yayloyalty')}
        error={value.min_points < value.increment ? MIN_BELOW_INCREMENT_MESSAGE() : undefined}
      >
        <PointsInput value={value.min_points} min={0} onValueChange={(min_points) => onChange({ min_points })} />
      </ConfigField>

      <ConfigField
        label={__('Maximum points per order', 'yayloyalty')}
        description={__('Leave empty for no cap beyond the percentage below.', 'yayloyalty')}
      >
        <OptionalCountInput
          value={value.max_points_per_order}
          unit={_n('point', 'points', value.max_points_per_order ?? 0, 'yayloyalty')}
          className={AMOUNT_INPUT_WIDTH}
          onValueChange={(max_points_per_order) => onChange({ max_points_per_order })}
        />
      </ConfigField>

      <ConfigField
        label={__('Maximum discount', 'yayloyalty')}
        description={__('At 100% a customer can pay for an order entirely in points.', 'yayloyalty')}
      >
        <div className="flex items-center gap-2">
          <NumberInputRoot
            size="small"
            className="w-28"
            min={1}
            max={100}
            isAllowed={({ floatValue }) => floatValue === undefined || floatValue <= 100}
            value={value.max_percent_of_discountable ?? 100}
            onValueChange={(percent) => onChange({ max_percent_of_discountable: percent < 100 ? percent : null })}
          >
            <NumberInputInput />
            <NumberInputUnit unit="%" />
          </NumberInputRoot>
          <span className="text-muted-foreground text-sm">
            {__('of the order total after other discounts', 'yayloyalty')}
          </span>
        </div>
      </ConfigField>

      {/* Total discount cap (config.max_total) stays hidden until redemption enforces it; nothing reads it yet. */}

      <ConfigField label={__('How customers redeem', 'yayloyalty')}>
        <RadioGroup
          className="gap-2.5 pt-1.5"
          value={value.redemption_mode ?? 'free_input'}
          onValueChange={(redemption_mode) => onChange({ redemption_mode: redemption_mode as RedemptionMode })}
        >
          <RadioChoice
            value="auto_max"
            label={__('Apply the maximum automatically', 'yayloyalty')}
            description={__('Customers tick Points discount and get the most the order allows.', 'yayloyalty')}
          />
          <RadioChoice
            value="free_input"
            label={__('Customers enter an amount', 'yayloyalty')}
            description={__('Between the minimum and the maximum, in multiples of the increment.', 'yayloyalty')}
          />
        </RadioGroup>
      </ConfigField>

      {/* No field for show_on: the reward keeps its default, cart and checkout. */}
      <ConfigField label={__('Return redeemed points when an order is refunded', 'yayloyalty')}>
        <RadioGroup
          className="gap-2.5 pt-1.5"
          value={value.refund_policy}
          onValueChange={(refund_policy) =>
            onChange({ refund_policy: refund_policy as FlexibleDiscountConfig['refund_policy'] })
          }
        >
          <RadioChoice
            value="proportional"
            label={__('Proportionally', 'yayloyalty')}
            description={__('A half refund returns half the points.', 'yayloyalty')}
          />
          <RadioChoice value="full" label={__('In full', 'yayloyalty')} />
          {/* Stored as "none"; "Never" is the wireframe's wording. */}
          <RadioChoice value="none" label={__('Never', 'yayloyalty')} />
        </RadioGroup>
      </ConfigField>
    </>
  );
}

/** One radio option: the label, with its description on the line below. */
function RadioChoice({ value, label, description }: { value: string; label: string; description?: string }) {
  return (
    <label className="flex cursor-pointer items-start gap-2.5">
      <RadioGroupItem value={value} className="mt-0.5" />
      <span className="flex flex-col gap-0.5">
        <span className="text-sm">{label}</span>
        {description && <span className="text-muted-foreground text-xs">{description}</span>}
      </span>
    </label>
  );
}

/** What points are worth at a rate, as a whole-points pair and 100 times it: "1 point = 1,000 ₫ off · 100 points = 100,000 ₫ off". */
function pointsWorth(rate: number): string {
  const pair = spendPairFromRate(rate);
  // A rate with no whole pair reads as what 1 point buys, as the storefront shows it.
  const { points, spend } = Number.isInteger(pair.points) ? pair : { points: 1, spend: 1 / rate };

  return [1, 100]
    .map((times) =>
      sprintf(
        /* translators: 1: a points amount, formatted; 2: the discount it buys, formatted in the store currency. */
        _n('%1$s point = %2$s off', '%1$s points = %2$s off', points * times, 'yayloyalty'),
        formatPoints(points * times),
        parseWPCurrency(spend * times),
      ),
    )
    .join(' · ');
}
