import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { InfoIcon } from 'lucide-react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { formatStoreNumber } from '@/lib/helpers/format.helper';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useTierSettingsQuery, useUpdateTierSettingsMutation } from './tiers.queries';

/**
 * The tiers on/off switch, laid out like the program's own status card, and the banner that follows a
 * placement or re-evaluation while it runs. The switch flips only once the merchant confirms.
 */
export function TierSwitchCard() {
  const queryClient = useQueryClient();
  const { data: settings, isPending, isError, refetch } = useTierSettingsQuery();
  const updateSettings = useUpdateTierSettingsMutation();
  const [confirming, setConfirming] = useState<'on' | 'off'>();

  const sweep = settings?.sweep ?? null;
  const hadSweep = useRef(false);

  // The poll stops once the sweep is gone: refresh the member counts and say so.
  useEffect(() => {
    if (hadSweep.current && !sweep) {
      void queryClient.invalidateQueries({ queryKey: ['tier'] });
      toast.success(__('Customer tiers updated.', 'yayloyalty'));
    }

    hadSweep.current = !!sweep;
  }, [sweep, queryClient]);

  if (isError) {
    return <ErrorState message={__('Could not load the tier settings.', 'yayloyalty')} onRetry={() => refetch()} />;
  }

  function save(enabled: boolean) {
    setConfirming(undefined);
    updateSettings.mutate(
      { tiers_enabled: enabled },
      {
        onError: (error) => toastApiError(error, __('Could not save the tier setting.', 'yayloyalty')),
      },
    );
  }

  return (
    <>
      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4">
          <div className="flex flex-col gap-1.5">
            <CardTitle>
              <label htmlFor="yayloyalty-tiers-enabled">{__('Enable tiers', 'yayloyalty')}</label>
            </CardTitle>
            <CardDescription>
              {__("Place customers into tiers by the points they've earned.", 'yayloyalty')}
            </CardDescription>
          </div>
          {isPending ? (
            <Skeleton className="h-6 w-11 rounded-full" />
          ) : (
            <Switch
              id="yayloyalty-tiers-enabled"
              className="w-11!"
              checked={settings.tiers_enabled}
              disabled={updateSettings.isPending}
              onCheckedChange={(checked) => setConfirming(checked ? 'on' : 'off')}
            />
          )}
        </CardHeader>
      </Card>

      {sweep && (
        <Alert variant="outline">
          <InfoIcon />
          <AlertTitle className="line-clamp-none">
            {sprintf(
              /* translators: 1: customers evaluated so far, 2: customers to evaluate. */
              __('Updating customer tiers… %1$s of %2$s', 'yayloyalty'),
              formatStoreNumber(sweep.done, 0),
              formatStoreNumber(sweep.total, 0),
            )}
          </AlertTitle>
        </Alert>
      )}

      {settings && (
        <>
          <ConfirmDialog
            open={confirming === 'on'}
            onOpenChange={(open) => !open && setConfirming(undefined)}
            title={__('Turn on tiers?', 'yayloyalty')}
            description={sprintf(
              /* translators: %s: the number of customers. */
              __(
                '%s customers will be placed into the tier their earned points qualify for. No entry bonus is paid for this placement.',
                'yayloyalty',
              ),
              formatStoreNumber(settings.customer_count, 0),
            )}
            confirmLabel={__('Turn on', 'yayloyalty')}
            cancelLabel={__('Cancel', 'yayloyalty')}
            onConfirm={() => save(true)}
          />
          <ConfirmDialog
            open={confirming === 'off'}
            onOpenChange={(open) => !open && setConfirming(undefined)}
            title={__('Turn off tiers?', 'yayloyalty')}
            description={__(
              'Customers keep their current tier, but nothing changes it until you turn tiers back on. Tier columns and the customer tier card are hidden while off.',
              'yayloyalty',
            )}
            confirmLabel={__('Turn off', 'yayloyalty')}
            cancelLabel={__('Cancel', 'yayloyalty')}
            variant="destructive"
            onConfirm={() => save(false)}
          />
        </>
      )}
    </>
  );
}
