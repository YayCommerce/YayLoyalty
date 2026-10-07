import { __, _n, sprintf } from '@wordpress/i18n';

import { can, CAP } from '@/lib/access';
import { parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import { usePurchaseCampaignsQuery } from '@/lib/queries/campaign.queries';
import { findBuiltinCampaign } from '@/lib/schema/campaign.schema';
import type { RewardConfigOf } from '@/lib/schema/reward.schema';
import { OptionalCountInput, PointsInput } from '@/components/ui/custom/amount-inputs';
import { ConfigField } from '@/components/ui/custom/config-field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

export type FlexibleDiscountConfig = RewardConfigOf<'flexible_discount'>;

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
  const earnBack = useEarnBackPercent(value.points_per_currency_unit);
  const incrementMoney = formatPointsAsMoney(value.increment, value.points_per_currency_unit);

  return (
    <>
      <ConfigField
        label={__('Conversion rate', 'yayloyalty')}
        description={
          earnBack === null
            ? undefined
            : sprintf(
                /* translators: %s: percent of spend returned as redeemable value, e.g. "1". */
                __('Customers earn back %s%% of what they spend at the default earning rate.', 'yayloyalty'),
                earnBack,
              )
        }
      >
        <div className="flex flex-wrap items-center gap-2">
          <PointsInput
            value={value.points_per_currency_unit}
            min={1}
            onValueChange={(points_per_currency_unit) => onChange({ points_per_currency_unit })}
          />
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

      <ConfigField
        label={__('Minimum points per redemption', 'yayloyalty')}
        description={__('The slider is disabled below this.', 'yayloyalty')}
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
          className="w-32"
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
            value={value.max_percent_of_subtotal ?? 100}
            onValueChange={(percent) => onChange({ max_percent_of_subtotal: percent < 100 ? percent : null })}
          >
            <NumberInputInput />
            <NumberInputUnit unit="%" />
          </NumberInputRoot>
          <span className="text-muted-foreground text-sm">{__('of the subtotal', 'yayloyalty')}</span>
        </div>
      </ConfigField>

      {/* Total discount cap (config.max_total) stays hidden until redemption enforces it; nothing reads it yet. */}

      <ConfigField
        label={__('Redemption increment', 'yayloyalty')}
        description={
          incrementMoney === null
            ? __('The slider moves in steps of this size.', 'yayloyalty')
            : sprintf(
                /* translators: %s: the money one increment is worth, formatted. */
                __('The slider moves in steps of this size — %s at a time here.', 'yayloyalty'),
                incrementMoney,
              )
        }
      >
        <PointsInput value={value.increment} min={1} onValueChange={(increment) => onChange({ increment })} />
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
          <label className="flex items-center gap-2 text-sm">
            <RadioGroupItem value="proportional" />
            {__('Proportionally', 'yayloyalty')}
            <span className="text-muted-foreground text-xs">
              {__('a half refund returns half the points', 'yayloyalty')}
            </span>
          </label>
          <label className="flex items-center gap-2 text-sm">
            <RadioGroupItem value="full" />
            {__('In full', 'yayloyalty')}
          </label>
          <label className="flex items-center gap-2 text-sm">
            {/* Stored as "none"; "Never" is the wireframe's wording. */}
            <RadioGroupItem value="none" />
            {__('Never', 'yayloyalty')}
          </label>
        </RadioGroup>
      </ConfigField>
    </>
  );
}

/**
 * Percent of spend a customer gets back at the default purchase rate, e.g.
 * 1 point per $1 against 100 points = $1 is "1". Null when the built-in purchase campaign or
 * either rate is missing — the line is dropped rather than guessed.
 */
function useEarnBackPercent(pointsPerCurrencyUnit: number): string | null {
  // Rewards and campaigns are separate capabilities: without campaigns access the line is dropped, not a 403 toast.
  const { data } = usePurchaseCampaignsQuery(can(CAP.MANAGE_CAMPAIGNS));
  const purchase = findBuiltinCampaign(data?.data, 'purchase');
  const pointsPerUnit = purchase?.config.points_per_unit;

  if (typeof pointsPerUnit !== 'number' || pointsPerUnit <= 0 || pointsPerCurrencyUnit <= 0) {
    return null;
  }

  return formatPoints((pointsPerUnit / pointsPerCurrencyUnit) * 100);
}
