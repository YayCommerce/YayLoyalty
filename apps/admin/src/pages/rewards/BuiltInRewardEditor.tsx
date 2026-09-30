import { useState } from 'react';
import { ChevronDown, ChevronUp, InfoIcon, TriangleAlert, X } from 'lucide-react';
import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, parseWPDate } from '@/lib/helpers/format.helper';
import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import { useCampaignsQuery } from '@/lib/queries/campaign.queries';
import { useRewardsQuery } from '@/lib/queries/reward.queries';
import type { Reward } from '@/lib/schema/reward.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ConfigField } from '@/components/ui/custom/config-field';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { UpgradeToProBanner } from '@/components/pro/UpgradeToPro';

/** Same slug CampaignRepository::DEFAULT_PURCHASE_SLUG seeds; the earn-back line reads its rate. */
const DEFAULT_PURCHASE_SLUG = 'default-purchase';

/** Config is untyped on the wire; narrowed here. Keys and defaults mirror Reward::default_config(). */
interface FlexibleDiscountConfig {
  points_per_currency_unit: number;
  min_points: number;
  /** null = no cap beyond the percentage. */
  max_points_per_order: number | null;
  /** null = up to 100% of the subtotal. */
  max_percent_of_subtotal: number | null;
  increment: number;
  show_on: ('cart' | 'checkout')[];
  refund_policy: 'proportional' | 'full' | 'none';
}

/**
 * Lite's Rewards screen: the built-in flexible-discount reward edited in
 * place. UI-only for now — the switch and Save change a local draft, nothing
 * is persisted until a reward update route exists.
 */
export default function BuiltInRewardEditor() {
  const { data, isPending, isError } = useRewardsQuery({ perPage: 100 });

  if (isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  if (isError) {
    return <p className="text-destructive text-sm">{__('Could not load rewards.', 'yayloyalty')}</p>;
  }

  const builtin = data.data.find((reward) => reward.is_builtin);

  return (
    <div className="flex flex-col gap-3">
      {!window.yayLoyaltyMeta.wcMeta.coupons_enabled && <CouponsDisabledNotice />}

      <Alert variant="outline">
        <InfoIcon />
        <AlertTitle>{__('Points are spent at checkout', 'yayloyalty')}</AlertTitle>
        <AlertDescription>
          {__(
            'Nothing is issued in advance: the points leave the balance as the order is placed, and the discount is applied to that order only. There is no coupon to lose and nothing to expire.',
            'yayloyalty',
          )}
        </AlertDescription>
      </Alert>

      {builtin ? (
        <BuiltInRewardCard reward={builtin} />
      ) : (
        <Empty>
          <EmptyHeader>
            <EmptyTitle>{__('No reward yet', 'yayloyalty')}</EmptyTitle>
            <EmptyDescription>
              {__('The built-in points discount should appear here automatically.', 'yayloyalty')}
            </EmptyDescription>
          </EmptyHeader>
        </Empty>
      )}

      <UpgradeToProBanner
        message={__(
          'Pro adds a rewards catalog — free shipping, free products and fixed or percentage coupons customers claim with points.',
          'yayloyalty',
        )}
      />
    </div>
  );
}

/** Dismissed for this page view only: coupons being off is a store state worth re-flagging on every visit. */
function CouponsDisabledNotice() {
  const [dismissed, setDismissed] = useState(false);

  if (dismissed) {
    return null;
  }

  const settingsUrl = `${window.yayLoyaltyMeta.wpMeta.adminUrl}admin.php?page=wc-settings&tab=general`;

  return (
    <Alert variant="outline">
      <TriangleAlert />
      <AlertDescription className="flex-row items-center justify-between gap-3">
        <span>
          {__('WooCommerce coupons must be enabled for points redemption to work.', 'yayloyalty')}{' '}
          <a href={settingsUrl} className="text-primary underline underline-offset-4">
            {__('Enable now', 'yayloyalty')}
          </a>
        </span>
        <Button
          variant="ghost"
          size="icon"
          className="size-6 shrink-0"
          aria-label={__('Dismiss', 'yayloyalty')}
          onClick={() => setDismissed(true)}
        >
          <X className="size-4" />
        </Button>
      </AlertDescription>
    </Alert>
  );
}

function BuiltInRewardCard({ reward }: { reward: Reward }) {
  const [expanded, setExpanded] = useState(true);
  const [status, setStatus] = useState(reward.status);
  const [config, setConfig] = useState(reward.config);
  const isOn = status === 'active';

  const value = config as unknown as FlexibleDiscountConfig;

  function update(next: Partial<FlexibleDiscountConfig>) {
    setConfig({ ...config, ...next });
  }

  function reset() {
    setStatus(reward.status);
    setConfig(reward.config);
  }

  return (
    <Card className={expanded ? 'border-primary gap-0 p-0' : 'gap-0 p-0'}>
      <CardContent
        className="flex cursor-pointer items-center gap-3.5 p-0 px-4.5 py-4"
        onClick={() => setExpanded((current) => !current)}
      >
        <div className="flex flex-1 flex-col gap-0.5">
          <div className="flex items-center gap-2">
            <span className="text-base font-semibold">{reward.name}</span>
            <Badge variant="outline" className="uppercase">
              {__('Default', 'yayloyalty')}
            </Badge>
          </div>
          <span className="text-muted-foreground text-xs">
            {__('The customer chooses how many points to use at checkout', 'yayloyalty')}
          </span>
        </div>

        {/* Its own click target: toggling on/off isn't "collapse this card". */}
        <div className="flex items-center gap-2" onClick={(event) => event.stopPropagation()}>
          <Switch checked={isOn} onCheckedChange={(checked) => setStatus(checked ? 'active' : 'paused')} />
          <span className="text-sm">{isOn ? __('On', 'yayloyalty') : __('Off', 'yayloyalty')}</span>
        </div>

        <span className="text-muted-foreground flex size-6 items-center justify-center">
          {expanded ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        </span>
      </CardContent>

      {expanded && (
        <CardContent className="flex flex-col gap-3.5 border-t p-4.5">
          <FlexibleDiscountFields value={value} onChange={update} />

          <div className="flex flex-wrap items-center gap-3 border-t pt-4">
            {/* UI-only: no reward update route yet. */}
            <Button size="sm">{__('Save changes', 'yayloyalty')}</Button>
            <Button variant="ghost" size="sm" onClick={reset}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <span className="text-muted-foreground ml-auto text-xs">
              {sprintf(
                /* translators: %s: the date the reward was last saved. */
                __('Last edited %s', 'yayloyalty'),
                parseWPDate(reward.updated_at),
              )}
            </span>
          </div>
        </CardContent>
      )}
    </Card>
  );
}

function FlexibleDiscountFields({
  value,
  onChange,
}: {
  value: FlexibleDiscountConfig;
  onChange: (next: Partial<FlexibleDiscountConfig>) => void;
}) {
  const currency = window.yayLoyaltyMeta.wcMeta.currency_data.currency;
  const earnBack = useEarnBackPercent(value.points_per_currency_unit);
  const incrementMoney = formatPointsAsMoney(value.increment, value.points_per_currency_unit);

  function toggleShowOn(place: 'cart' | 'checkout', checked: boolean) {
    const others = value.show_on.filter((entry) => entry !== place);

    onChange({ show_on: checked ? [...others, place] : others });
  }

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
          <NumberInputRoot
            size="small"
            className="w-32"
            min={1}
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

      <ConfigField
        label={__('Minimum points per redemption', 'yayloyalty')}
        description={__('The slider is disabled below this.', 'yayloyalty')}
      >
        <PointsInput value={value.min_points} min={0} onValueChange={(min_points) => onChange({ min_points })} />
      </ConfigField>

      <ConfigField
        label={__('Maximum points per order', 'yayloyalty')}
        description={__('0 means no cap beyond the percentage below.', 'yayloyalty')}
      >
        {/* Stored null means uncapped; a stored 0 would block every redemption (Clamp), so 0 is never written. */}
        <PointsInput
          value={value.max_points_per_order ?? 0}
          min={0}
          onValueChange={(points) => onChange({ max_points_per_order: points > 0 ? points : null })}
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

      <ConfigField label={__('Where it appears', 'yayloyalty')}>
        <div className="flex flex-col gap-2 pt-1.5">
          <label className="flex items-center gap-2 text-sm">
            <Switch
              size="sm"
              checked={value.show_on.includes('cart')}
              onCheckedChange={(checked) => toggleShowOn('cart', checked)}
            />
            {__('Cart', 'yayloyalty')}
          </label>
          <label className="flex items-center gap-2 text-sm">
            <Switch
              size="sm"
              checked={value.show_on.includes('checkout')}
              onCheckedChange={(checked) => toggleShowOn('checkout', checked)}
            />
            {__('Checkout', 'yayloyalty')}
          </label>
        </div>
      </ConfigField>

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

function PointsInput({
  value,
  min,
  onValueChange,
}: {
  value: number;
  min: number;
  onValueChange: (value: number) => void;
}) {
  return (
    <NumberInputRoot size="small" className="w-32" min={min} value={value} onValueChange={onValueChange}>
      <NumberInputInput />
      <NumberInputUnit unit={_n('point', 'points', value, 'yayloyalty')} />
    </NumberInputRoot>
  );
}

/**
 * Percent of spend a customer gets back at the default purchase rate, e.g.
 * 1 point per $1 against 100 points = $1 is "1". Null when that campaign or
 * either rate is missing — the line is dropped rather than guessed.
 */
function useEarnBackPercent(pointsPerCurrencyUnit: number): string | null {
  const { data } = useCampaignsQuery({ perPage: 100 });
  const purchase = data?.data.find((campaign) => campaign.slug === DEFAULT_PURCHASE_SLUG);
  const pointsPerUnit = purchase?.config.points_per_unit;

  if (typeof pointsPerUnit !== 'number' || pointsPerUnit <= 0 || pointsPerCurrencyUnit <= 0) {
    return null;
  }

  return formatPoints((pointsPerUnit / pointsPerCurrencyUnit) * 100);
}
