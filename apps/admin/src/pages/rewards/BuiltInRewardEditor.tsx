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
import { Skeleton } from '@/components/ui/skeleton';
import { UpgradeToProBanner } from '@/components/pro/UpgradeToPro';
import { FlexibleDiscountConfig, FlexibleDiscountFields } from './FlexibleDiscountFields';

/**
 * Lite's Rewards screen: the built-in flexible-discount reward edited in
 * place. The switch and fields change a local draft; Save changes writes
 * status and config together.
 */
export default function BuiltInRewardEditor() {
  // Only the built-in's type: rows a downgraded site keeps from Pro are never fetched.
  const { data, isPending, isError } = useRewardsQuery({ type: 'flexible_discount' });

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
  const [status, setStatus] = useState(reward.status);
  const [config, setConfig] = useState(reward.config);
  const mutation = useUpdateRewardMutation(reward.id);
  const isOn = status === 'active';

  const value = config as unknown as FlexibleDiscountConfig;

  function update(next: Partial<FlexibleDiscountConfig>) {
    setConfig({ ...config, ...next });
  }

  function reset() {
    setStatus(reward.status);
    setConfig(reward.config);
  }

  async function save() {
    try {
      await mutation.mutateAsync({ status, config });
      toast.success(__('Reward saved.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Could not save the reward.', 'yayloyalty'));
    }
  }

  return (
    <BuiltInConfigCard
      name={reward.name}
      subtitle={flexibleDiscountSubtitle()}
      isOn={isOn}
      onToggle={(checked) => setStatus(checked ? 'active' : 'paused')}
      expanded={expanded}
      onToggleExpanded={() => setExpanded((current) => !current)}
      onSave={save}
      onCancel={reset}
      saving={mutation.isPending}
      updatedAt={reward.updated_at}
    >
      <FlexibleDiscountFields value={value} onChange={update} />
    </BuiltInConfigCard>
  );
}
