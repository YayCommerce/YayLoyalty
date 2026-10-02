import { useState } from 'react';
import { ChevronDown, ChevronUp, InfoIcon, TriangleAlert, X } from 'lucide-react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { parseWPDate } from '@/lib/helpers/format.helper';
import { useRewardsQuery, useUpdateRewardMutation } from '@/lib/queries/reward.queries';
import type { Reward } from '@/lib/schema/reward.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
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
            <Button size="sm" disabled={mutation.isPending} onClick={save}>
              {mutation.isPending ? __('Saving…', 'yayloyalty') : __('Save changes', 'yayloyalty')}
            </Button>
            <Button variant="ghost" size="sm" disabled={mutation.isPending} onClick={reset}>
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
