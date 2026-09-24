import { ReactNode, useState } from 'react';
import { ChevronDown, ChevronUp, InfoIcon } from 'lucide-react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { parseWPCurrency, parseWPDate } from '@/lib/helpers/format.helper';
import { roundPoints } from '@/lib/points-math/points-math';
import {
  useCampaignsQuery,
  useUpdateCampaignConfigMutation,
  useUpdateCampaignStatusMutation,
} from '@/lib/queries/campaign.queries';
import type { Campaign } from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';

/** Display order for the three built-ins; same as CampaignTable's group order. */
const BUILTIN_TRIGGER_ORDER: Campaign['trigger'][] = ['purchase', 'signup', 'review'];

/**
 * Lite's campaigns list: three always-present editable cards, from the same
 * list CampaignTable reads, filtered to is_builtin. Loading/error/empty are
 * handled here once. The On/Off switch writes immediately (a toggle, not a
 * draft); config fields are a draft until "Save changes".
 */
export default function BuiltInCampaignList() {
  const { data, isPending, isError } = useCampaignsQuery({ perPage: 100 });
  const [expandedId, setExpandedId] = useState<number | undefined>();

  if (isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  if (isError) {
    return <p className="text-destructive text-sm">{__('Could not load campaigns.', 'yayloyalty')}</p>;
  }

  const campaigns = data.data
    .filter((campaign) => campaign.is_builtin)
    .sort((a, b) => BUILTIN_TRIGGER_ORDER.indexOf(a.trigger) - BUILTIN_TRIGGER_ORDER.indexOf(b.trigger));

  if (campaigns.length === 0) {
    return (
      <Empty>
        <EmptyHeader>
          <EmptyTitle>{__('No campaigns yet', 'yayloyalty')}</EmptyTitle>
          <EmptyDescription>
            {__('Built-in campaigns should appear here automatically.', 'yayloyalty')}
          </EmptyDescription>
        </EmptyHeader>
      </Empty>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-muted-foreground -mt-1 text-sm">
        {__('Three ways customers earn points. Pro lets you add more and schedule them.', 'yayloyalty')}
      </p>

      {campaigns.map((campaign) => (
        <BuiltInCampaignCard
          key={campaign.id}
          campaign={campaign}
          expanded={expandedId === campaign.id}
          onToggleExpanded={() => setExpandedId((current) => (current === campaign.id ? undefined : campaign.id))}
        />
      ))}

      <UpsellBanner />
    </div>
  );
}

function UpsellBanner() {
  // Same destination as components/pro/UpgradeToPro.tsx, which does not export it.
  const yayLoyaltyProUrl = 'https://yaycommerce.com/yayloyalty-for-woocommerce/';

  return (
    <Alert variant="outline">
      <InfoIcon />
      <AlertDescription className="flex-row items-center justify-between gap-3">
        <span>
          {__(
            'Running a sale? Pro lets you add campaigns like double points weekends, category boosts and birthday rewards.',
            'yayloyalty',
          )}
        </span>
        <Button variant="link" size="sm" className="h-auto shrink-0 p-0" onClick={() => window.open(yayLoyaltyProUrl)}>
          {__('Upgrade', 'yayloyalty')}
        </Button>
      </AlertDescription>
    </Alert>
  );
}

function BuiltInCampaignCard({
  campaign,
  expanded,
  onToggleExpanded,
}: {
  campaign: Campaign;
  expanded: boolean;
  onToggleExpanded: () => void;
}) {
  // Local draft; config only persists on "Save changes". The switch is not part of the draft —
  // it writes immediately, below.
  const [status, setStatus] = useState(campaign.status);
  const [config, setConfig] = useState(campaign.config);
  const isOn = status === 'active';

  const statusMutation = useUpdateCampaignStatusMutation(campaign.id);
  const configMutation = useUpdateCampaignConfigMutation(campaign.id);

  function reset() {
    setStatus(campaign.status);
    setConfig(campaign.config);
    onToggleExpanded();
  }

  async function toggleOn(checked: boolean) {
    const next = checked ? 'active' : 'paused';
    setStatus(next); // optimistic; reverted below on failure

    try {
      await statusMutation.mutateAsync(next);
    } catch (error) {
      setStatus(campaign.status);

      await toastApiError(error, __('Could not update the campaign.', 'yayloyalty'));
    }
  }

  async function saveConfig() {
    try {
      await configMutation.mutateAsync(config);
      toast.success(__('Campaign saved.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Could not save the campaign.', 'yayloyalty'));
    }
  }

  return (
    <Card className={expanded ? 'border-primary gap-0 p-0' : 'gap-0 p-0'}>
      <CardContent className="flex cursor-pointer items-center gap-3.5 p-0 px-4.5 py-4" onClick={onToggleExpanded}>
        <div className="flex flex-1 flex-col gap-0.5">
          <div className="flex items-center gap-2">
            <span className="text-base font-semibold">{campaign.name}</span>
            {campaign.is_builtin && (
              <Badge variant="outline" className="uppercase">
                {__('Default', 'yayloyalty')}
              </Badge>
            )}
          </div>
          <span className="text-muted-foreground text-xs">{summarize(campaign.trigger, config)}</span>
        </div>

        {/* Its own click target: toggling on/off isn't "open this card". */}
        <div className="flex items-center gap-2" onClick={(event) => event.stopPropagation()}>
          <Switch checked={isOn} disabled={statusMutation.isPending} onCheckedChange={toggleOn} />
          <span className="text-sm">{isOn ? __('On', 'yayloyalty') : __('Off', 'yayloyalty')}</span>
        </div>

        <span className="text-muted-foreground flex size-6 items-center justify-center">
          {expanded ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        </span>
      </CardContent>

      {expanded && (
        <CardContent className="flex flex-col gap-3.5 border-t p-4.5">
          {campaign.trigger === 'purchase' && (
            <PurchaseFields config={config} onChange={(next) => setConfig({ ...config, ...next })} />
          )}
          {campaign.trigger === 'signup' && (
            <SignupFields config={config} onChange={(next) => setConfig({ ...config, ...next })} />
          )}
          {campaign.trigger === 'review' && (
            <ReviewFields config={config} onChange={(next) => setConfig({ ...config, ...next })} />
          )}

          <div className="flex flex-wrap items-center gap-3 border-t pt-4">
            <Button size="sm" disabled={configMutation.isPending} onClick={saveConfig}>
              {configMutation.isPending ? __('Saving…', 'yayloyalty') : __('Save changes', 'yayloyalty')}
            </Button>
            <Button variant="link" size="sm" className="h-auto p-0" onClick={reset}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <span className="text-muted-foreground ml-auto text-xs">
              {sprintf(
                /* translators: %s: the date the campaign was last saved. */
                __('Last edited %s', 'yayloyalty'),
                parseWPDate(campaign.updated_at),
              )}
            </span>
          </div>
        </CardContent>
      )}
    </Card>
  );
}

/** Config is untyped on the wire; narrowed per trigger here. */
interface PurchaseConfig {
  points_per_unit: number;
  base: 'after_discounts' | 'before_discounts';
  include_shipping: boolean;
  include_tax: boolean;
  rounding: 'down' | 'nearest' | 'up';
  min_subtotal: number;
  exclude_coupon_orders: boolean;
}

/**
 * One label + control row. A plain grid, not components/ui/field.tsx: its
 * conditional `has-[...]:items-start` fought the fixed label column and
 * produced uneven row gaps.
 */
function ConfigField({ label, description, children }: { label: string; description?: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[14rem_1fr] items-start gap-x-5">
      <span className="pt-2 text-sm font-medium">{label}</span>
      <div className="flex flex-col gap-1.5">
        {children}
        {description && <p className="text-muted-foreground text-xs">{description}</p>}
      </div>
    </div>
  );
}

function PurchaseFields({
  config,
  onChange,
}: {
  config: Record<string, unknown>;
  onChange: (next: Partial<PurchaseConfig>) => void;
}) {
  const value = config as unknown as PurchaseConfig;
  const currency = window.yayLoyaltyMeta.wcMeta.currency_data.currency;
  const worked = worksOut(value.points_per_unit, value.rounding);

  return (
    <>
      <ConfigField label={__('Award', 'yayloyalty')}>
        <div className="flex flex-wrap items-center gap-2">
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            value={value.points_per_unit}
            onValueChange={(points_per_unit) => onChange({ points_per_unit })}
          >
            <NumberInputInput />
            <NumberInputUnit
              unit={value.points_per_unit === 1 ? __('point', 'yayloyalty') : __('points', 'yayloyalty')}
            />
          </NumberInputRoot>
          <span className="text-muted-foreground text-sm">
            {sprintf(
              /* translators: 1: one unit of the store currency, formatted; 2: the site's currency code, e.g. USD. */
              __('per %1$s (%2$s) spent', 'yayloyalty'),
              parseWPCurrency(1),
              currency,
            )}
          </span>
        </div>
      </ConfigField>

      <div className="bg-muted flex items-center gap-3 rounded-md border p-3.5">
        <span className="text-sm font-semibold">{worked}</span>
      </div>

      <ConfigField
        label={__('Base amount', 'yayloyalty')}
        description={__(
          'What the points are calculated on. After discounts means a coupon reduces the points too.',
          'yayloyalty',
        )}
      >
        <Segmented
          value={value.base}
          onValueChange={(base) => base && onChange({ base: base as PurchaseConfig['base'] })}
        >
          <SegmentedItem value="after_discounts">{__('After discounts', 'yayloyalty')}</SegmentedItem>
          <SegmentedItem value="before_discounts">{__('Before discounts', 'yayloyalty')}</SegmentedItem>
        </Segmented>
      </ConfigField>

      <ConfigField label={__('Include in the award basis', 'yayloyalty')}>
        <div className="flex flex-wrap items-center gap-5">
          <label className="flex items-center gap-2 text-sm">
            <Switch
              size="sm"
              checked={value.include_shipping}
              onCheckedChange={(include_shipping) => onChange({ include_shipping })}
            />
            {__('Shipping', 'yayloyalty')}
          </label>
          <label className="flex items-center gap-2 text-sm">
            <Switch
              size="sm"
              checked={value.include_tax}
              onCheckedChange={(include_tax) => onChange({ include_tax })}
            />
            {__('Tax', 'yayloyalty')}
          </label>
        </div>
      </ConfigField>

      <ConfigField label={__('Rounding', 'yayloyalty')}>
        <Segmented
          value={value.rounding}
          onValueChange={(rounding) => rounding && onChange({ rounding: rounding as PurchaseConfig['rounding'] })}
        >
          <SegmentedItem value="down">{__('Down', 'yayloyalty')}</SegmentedItem>
          <SegmentedItem value="nearest">{__('Nearest', 'yayloyalty')}</SegmentedItem>
          <SegmentedItem value="up">{__('Up', 'yayloyalty')}</SegmentedItem>
        </Segmented>
      </ConfigField>

      <ConfigField
        label={__('Minimum order subtotal', 'yayloyalty')}
        description={__('Orders below this earn nothing. 0 means every order earns.', 'yayloyalty')}
      >
        <NumberInputRoot
          size="small"
          className="w-32"
          min={0}
          decimalScale={2}
          fixedDecimalScale
          value={value.min_subtotal}
          onValueChange={(min_subtotal) => onChange({ min_subtotal })}
        >
          <NumberInputInput />
          <NumberInputUnit unit={currency} />
        </NumberInputRoot>
      </ConfigField>

      <ConfigField label={__('Coupon orders', 'yayloyalty')}>
        <label className="flex items-center gap-2 text-sm">
          <Switch
            checked={value.exclude_coupon_orders}
            onCheckedChange={(exclude_coupon_orders) => onChange({ exclude_coupon_orders })}
          />
          {__('Exclude orders that used a coupon', 'yayloyalty')}
        </label>
      </ConfigField>

    </>
  );
}

interface SignupConfig {
  points: number;
}

function SignupFields({
  config,
  onChange,
}: {
  config: Record<string, unknown>;
  onChange: (next: Partial<SignupConfig>) => void;
}) {
  const value = config as unknown as SignupConfig;

  return (
    <ConfigField
      label={__('Award', 'yayloyalty')}
      description={__('Awarded once, the first time a customer creates an account.', 'yayloyalty')}
    >
      <NumberInputRoot
        size="small"
        className="w-28"
        min={0}
        value={value.points}
        onValueChange={(points) => onChange({ points })}
      >
        <NumberInputInput />
        <NumberInputUnit unit={__('points', 'yayloyalty')} />
      </NumberInputRoot>
    </ConfigField>
  );
}

interface ReviewConfig {
  points: number;
  require_verified: boolean;
  one_per_product: boolean;
}

function ReviewFields({
  config,
  onChange,
}: {
  config: Record<string, unknown>;
  onChange: (next: Partial<ReviewConfig>) => void;
}) {
  const value = config as unknown as ReviewConfig;

  return (
    <>
      <ConfigField label={__('Award', 'yayloyalty')} description={__('Per approved product review.', 'yayloyalty')}>
        <NumberInputRoot
          size="small"
          className="w-28"
          min={0}
          value={value.points}
          onValueChange={(points) => onChange({ points })}
        >
          <NumberInputInput />
          <NumberInputUnit unit={__('points', 'yayloyalty')} />
        </NumberInputRoot>
      </ConfigField>

      <ConfigField label={__('Eligibility', 'yayloyalty')}>
        <div className="flex flex-col gap-2">
          <label className="flex items-center gap-2 text-sm">
            <Switch
              checked={value.require_verified}
              onCheckedChange={(require_verified) => onChange({ require_verified })}
            />
            {__('Verified purchases only', 'yayloyalty')}
          </label>
          <label className="flex items-center gap-2 text-sm">
            <Switch
              checked={value.one_per_product}
              onCheckedChange={(one_per_product) => onChange({ one_per_product })}
            />
            {__('One review per product', 'yayloyalty')}
          </label>
        </div>
      </ConfigField>
    </>
  );
}

/** The collapsed row's one-line summary, from the current (possibly edited) config. */
function summarize(trigger: Campaign['trigger'], config: Record<string, unknown>): string {
  switch (trigger) {
    case 'purchase': {
      const value = config as unknown as PurchaseConfig;

      return sprintf(
        /* translators: 1: points awarded per currency unit spent, e.g. "1"; 2: one unit of the store currency, formatted. */
        __('%1$s point(s) per %2$s spent', 'yayloyalty'),
        String(value.points_per_unit),
        parseWPCurrency(1),
      );
    }

    case 'signup': {
      const value = config as unknown as SignupConfig;

      return sprintf(
        /* translators: %d: points awarded on signup. */
        __('%d points, once per customer', 'yayloyalty'),
        value.points,
      );
    }

    case 'review': {
      const value = config as unknown as ReviewConfig;

      return value.require_verified
        ? sprintf(
            /* translators: %d: points awarded per review. */
            __('%d points, verified purchases only', 'yayloyalty'),
            value.points,
          )
        : sprintf(
            /* translators: %d: points awarded per review. */
            __('%d points', 'yayloyalty'),
            value.points,
          );
    }

    default:
      return '';
  }
}

/** The worked-example banner, live as the config changes. */
function worksOut(pointsPerUnit: number, rounding: PurchaseConfig['rounding']): string {
  const exampleSubtotal = 50;
  const points = roundPoints(exampleSubtotal * pointsPerUnit, rounding);

  return sprintf(
    /* translators: 1: an example order subtotal, formatted as currency; 2: the points it would earn. */
    __('A %1$s order earns %2$d points', 'yayloyalty'),
    parseWPCurrency(exampleSubtotal),
    points,
  );
}
