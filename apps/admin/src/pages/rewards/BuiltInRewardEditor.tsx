import { useState } from 'react';
import { InfoIcon, TriangleAlert, X } from 'lucide-react';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { flexibleDiscountSubtitle } from '@/lib/helpers/reward.helper';
import { useRewardsQuery, useUpdateRewardMutation } from '@/lib/queries/reward.queries';
import type { Reward } from '@/lib/schema/reward.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { BuiltInConfigCard } from '@/components/ui/custom/built-in-config-card';
import { ConfigField } from '@/components/ui/custom/config-field';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { UpgradeToProBanner } from '@/components/pro/UpgradeToPro';
import { FlexibleDiscountConfig, FlexibleDiscountFields } from './FlexibleDiscountFields';
import { rewardCouponCodeCopy } from './reward-coupon-code.copy';

/**
 * Lite's Rewards screen: the built-in flexible-discount reward edited in
 * place. The switch and fields change a local draft; Save changes writes
 * name, status and config together.
 */
export default function BuiltInRewardEditor() {
  // Only the built-in's type: rows a downgraded site keeps from Pro are never fetched.
  const { data, isPending, isError, refetch } = useRewardsQuery({ type: 'flexible_discount' });

  if (isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  if (isError) {
    return <ErrorState message={__('Could not load rewards.', 'yayloyalty')} onRetry={() => refetch()} />;
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

      {builtin && <BuiltInRewardCard reward={builtin} />}

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
  // What was last saved: the response, not the list refetch, so Save changes disables as the save lands.
  const [baseline, setBaseline] = useState({ name: reward.name, status: reward.status, config: reward.config });
  const [name, setName] = useState(reward.name);
  const [status, setStatus] = useState(reward.status);
  const [config, setConfig] = useState(reward.config);
  const mutation = useUpdateRewardMutation(reward.id);
  const isOn = status === 'active';
  const isNameEmpty = name.trim() === '';
  const isDirty =
    name !== baseline.name || status !== baseline.status || JSON.stringify(config) !== JSON.stringify(baseline.config);

  const value = config as unknown as FlexibleDiscountConfig;
  const couponCode = rewardCouponCodeCopy();

  function update(next: Partial<FlexibleDiscountConfig>) {
    setConfig({ ...config, ...next });
  }

  function reset() {
    setName(baseline.name);
    setStatus(baseline.status);
    setConfig(baseline.config);
  }

  async function save() {
    try {
      const saved = await mutation.mutateAsync({ name: name.trim(), status, config });
      setBaseline({ name: saved.name, status: saved.status, config: saved.config });
      setName(saved.name);
      setStatus(saved.status);
      setConfig(saved.config);
      toast.success(__('Reward saved.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Could not save the reward.', 'yayloyalty'));
    }
  }

  return (
    <BuiltInConfigCard
      name={baseline.name}
      subtitle={flexibleDiscountSubtitle(baseline.config)}
      isOn={isOn}
      onToggle={(checked) => setStatus(checked ? 'active' : 'paused')}
      toggleLoading={mutation.isPending}
      expanded={expanded}
      onToggleExpanded={() => setExpanded((current) => !current)}
      onSave={isNameEmpty ? undefined : save}
      onCancel={reset}
      isDirty={isDirty}
      saving={mutation.isPending}
      updatedAt={reward.updated_at}
    >
      <ConfigField
        label={__('Name', 'yayloyalty')}
        description={__('Customers see this name in the launcher and in My Account.', 'yayloyalty')}
        error={isNameEmpty ? __('A reward needs a name.', 'yayloyalty') : undefined}
      >
        <Input
          aria-label={__('Name', 'yayloyalty')}
          aria-invalid={isNameEmpty}
          value={name}
          onChange={(event) => setName(event.target.value)}
        />
      </ConfigField>
      <ConfigField label={couponCode.label} description={couponCode.description}>
        <Input aria-label={couponCode.label} value={reward.coupon_code} readOnly className="font-mono" />
      </ConfigField>
      <FlexibleDiscountFields value={value} onChange={update} />
    </BuiltInConfigCard>
  );
}
