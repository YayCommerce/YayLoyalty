import { useState } from 'react';
import { toast } from 'sonner';
import { __, _n, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { pointsPerCurrencyLabel } from '@/lib/helpers/points.helper';
import { roundPoints, type RoundingMode } from '@/lib/points-math/points-math';
import {
  useCampaignsQuery,
  useUpdateCampaignConfigMutation,
  useUpdateCampaignStatusMutation,
} from '@/lib/queries/campaign.queries';
import type { Campaign, PurchaseConfig, ReviewConfig, SignupConfig } from '@/lib/schema/campaign.schema';
import { AMOUNT_INPUT_WIDTH } from '@/components/ui/custom/amount-inputs';
import { BuiltInConfigCard } from '@/components/ui/custom/built-in-config-card';
import { ConfigField } from '@/components/ui/custom/config-field';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { UpgradeToProBanner } from '@/components/pro/UpgradeToPro';
import { BaseAmountSegmented, PurchaseRateInput, RoundingSegmented } from './PurchaseConfigControls';

/** Display order for the three built-ins; same as CampaignTable's group order. */
const BUILTIN_TRIGGER_ORDER: Campaign['trigger'][] = ['purchase', 'signup', 'review'];

/**
 * Lite's campaigns list: three always-present editable cards, from the same
 * list CampaignTable reads, filtered to is_builtin. Loading/error/empty are
 * handled here once. The On/Off switch writes immediately (a toggle, not a
 * draft); config fields are a draft until "Save changes".
 */
export default function BuiltInCampaignList() {
  const { data, isPending, isError, refetch } = useCampaignsQuery({ perPage: 100 });
  const [expandedId, setExpandedId] = useState<number | undefined>();

  if (isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  if (isError) {
    return <ErrorState message={__('Could not load campaigns.', 'yayloyalty')} onRetry={() => refetch()} />;
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

      <UpgradeToProBanner
        message={__(
          'Running a sale? Pro lets you add campaigns like double points weekends, category boosts and birthday rewards.',
          'yayloyalty',
        )}
      />
    </div>
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
  // What was last saved: the response, not the list refetch, so Save changes disables as the save lands.
  const [savedConfig, setSavedConfig] = useState(campaign.config);
  const [config, setConfig] = useState(campaign.config);
  const isOn = status === 'active';
  const isDirty = JSON.stringify(config) !== JSON.stringify(savedConfig);

  const statusMutation = useUpdateCampaignStatusMutation(campaign.id);
  const configMutation = useUpdateCampaignConfigMutation(campaign.id);

  // Status is left alone: the switch has already written it.
  function reset() {
    setConfig(savedConfig);
    onToggleExpanded();
  }

  async function toggleOn(checked: boolean) {
    const next = checked ? 'active' : 'paused';
    setStatus(next); // Optimistic; reverted below on failure.

    try {
      await statusMutation.mutateAsync(next);
    } catch (error) {
      setStatus(campaign.status);

      await toastApiError(error, __('Could not update the campaign.', 'yayloyalty'));
    }
  }

  async function saveConfig() {
    try {
      const saved = await configMutation.mutateAsync(config);
      setSavedConfig(saved.config);
      setConfig(saved.config);
      toast.success(__('Campaign saved.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Could not save the campaign.', 'yayloyalty'));
    }
  }

  return (
    <BuiltInConfigCard
      name={campaign.name}
      subtitle={summarize(campaign.trigger, config)}
      isOn={isOn}
      onToggle={toggleOn}
      toggleLoading={statusMutation.isPending}
      expanded={expanded}
      onToggleExpanded={onToggleExpanded}
      onSave={saveConfig}
      onCancel={reset}
      isDirty={isDirty}
      saving={configMutation.isPending}
      updatedAt={campaign.updated_at}
    >
      {campaign.trigger === 'purchase' && (
        <PurchaseFields config={config} onChange={(next) => setConfig({ ...config, ...next })} />
      )}
      {campaign.trigger === 'signup' && (
        <SignupFields config={config} onChange={(next) => setConfig({ ...config, ...next })} />
      )}
      {campaign.trigger === 'review' && (
        <ReviewFields config={config} onChange={(next) => setConfig({ ...config, ...next })} />
      )}
    </BuiltInConfigCard>
  );
}

function PurchaseFields({
  config,
  onChange,
}: {
  config: Record<string, unknown>;
  onChange: (next: Partial<PurchaseConfig>) => void;
}) {
  const value = config as unknown as Required<PurchaseConfig>;
  const currency = storeCurrency();
  const worked = worksOut(value.points_per_unit, value.rounding);

  return (
    <>
      <ConfigField label={__('Award', 'yayloyalty')}>
        <PurchaseRateInput
          value={value.points_per_unit}
          onValueChange={(points_per_unit) => onChange({ points_per_unit })}
        />
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
        <BaseAmountSegmented value={value.base} onValueChange={(base) => onChange({ base })} />
      </ConfigField>

      <ConfigField label={__('Tax', 'yayloyalty')}>
        <label className="flex items-center gap-2 text-sm">
          <Switch checked={value.include_tax} onCheckedChange={(include_tax) => onChange({ include_tax })} />
          {__('Add each line’s tax to the amount counted', 'yayloyalty')}
        </label>
      </ConfigField>

      <ConfigField label={__('Rounding', 'yayloyalty')}>
        <RoundingSegmented value={value.rounding} onValueChange={(rounding) => onChange({ rounding })} />
      </ConfigField>

      <ConfigField
        label={__('Minimum order subtotal', 'yayloyalty')}
        description={__('Orders below this earn nothing. 0 means every order earns.', 'yayloyalty')}
      >
        <NumberInputRoot
          size="small"
          className={AMOUNT_INPUT_WIDTH}
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

function SignupFields({
  config,
  onChange,
}: {
  config: Record<string, unknown>;
  onChange: (next: Partial<SignupConfig>) => void;
}) {
  const value = config as unknown as Required<SignupConfig>;

  return (
    <ConfigField
      label={__('Award', 'yayloyalty')}
      description={__('Awarded once, the first time a customer creates an account.', 'yayloyalty')}
    >
      <NumberInputRoot
        size="small"
        className={AMOUNT_INPUT_WIDTH}
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

function ReviewFields({
  config,
  onChange,
}: {
  config: Record<string, unknown>;
  onChange: (next: Partial<ReviewConfig>) => void;
}) {
  const value = config as unknown as Required<ReviewConfig>;

  return (
    <>
      <ConfigField label={__('Award', 'yayloyalty')} description={__('Per approved product review.', 'yayloyalty')}>
        <NumberInputRoot
          size="small"
          className={AMOUNT_INPUT_WIDTH}
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
      const value = config as unknown as Required<PurchaseConfig>;

      return sprintf(
        /* translators: %s: a purchase rate, e.g. "1 pt per $1.00". */
        __('%s spent', 'yayloyalty'),
        pointsPerCurrencyLabel(value.points_per_unit),
      );
    }

    case 'signup': {
      const value = config as unknown as Required<SignupConfig>;

      return sprintf(
        /* translators: %d: points awarded on signup. */
        _n('%d point, once per customer', '%d points, once per customer', value.points, 'yayloyalty'),
        value.points,
      );
    }

    case 'review': {
      const value = config as unknown as Required<ReviewConfig>;

      return value.require_verified
        ? sprintf(
            /* translators: %d: points awarded per review. */
            _n('%d point, verified purchases only', '%d points, verified purchases only', value.points, 'yayloyalty'),
            value.points,
          )
        : sprintf(
            /* translators: %d: points awarded per review. */
            _n('%d point', '%d points', value.points, 'yayloyalty'),
            value.points,
          );
    }

    default:
      return '';
  }
}

/** The worked-example banner, live as the config changes. */
function worksOut(pointsPerUnit: number, rounding: RoundingMode): string {
  const exampleSubtotal = 50;
  const points = roundPoints(exampleSubtotal * pointsPerUnit, rounding);

  return sprintf(
    /* translators: 1: an example order subtotal, formatted as currency; 2: the points it would earn. */
    _n('A %1$s order earns %2$d point', 'A %1$s order earns %2$d points', points, 'yayloyalty'),
    parseWPCurrency(exampleSubtotal),
    points,
  );
}
