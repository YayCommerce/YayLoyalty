import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { toastApiError } from '@/lib/api/api';
import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Customer } from '@/lib/schema/customer.schema';
import type { CustomerTier } from '@/lib/schema/tier.schema';
import { Button, LoadingButton } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { CustomerDrawerStat } from '@/pages/customers/drawer/CustomerDrawerStat';
import { DELETED_ACCOUNT_ACTION_REASON } from '@/pages/customers/drawer/customer-drawer.copy';
import { TierBadge } from '../tiers/TierBadge';
import {
  useAssignCustomerTierMutation,
  useCustomerTierQuery,
  useRemoveCustomerTierMutation,
  useTiersEnabled,
  useTiersQuery,
} from '../tiers/tiers.queries';
import {
  CUSTOMER_TIER_ASSIGN_DEFAULTS,
  customerTierAssignSchema,
  type CustomerTierAssignValues,
  MANUAL_TIER_DAYS_MAX,
  manualTierEndsOn,
  toAssignCustomerTierInput,
} from './customer-tier-assign.form';

/**
 * The drawer's tier card: the customer's tier, the way to the next one, and the manual floor staff set or
 * remove here. Renders nothing while tiers are off.
 */
export function CustomerTierCard({ customer }: { customer: Customer }) {
  const enabled = useTiersEnabled();
  const query = useCustomerTierQuery(customer.user_id, enabled);
  const [dialog, setDialog] = useState<'assign' | 'remove'>();
  const isDeleted = !!customer.deleted_at;

  if (!enabled) {
    return null;
  }

  if (query.isError) {
    return <ErrorState message={__('Could not load the tier.', 'yayloyalty')} onRetry={() => query.refetch()} />;
  }

  if (query.isPending) {
    return <Skeleton className="h-36 w-full rounded-md" />;
  }

  const { tier, next, progress_value: progress, achieved_at: achievedAt, manual } = query.data;

  return (
    <div className="flex flex-col gap-2">
      <div className="rounded-md border">
        <div className="grid grid-cols-2 gap-4 p-4">
          <CustomerDrawerStat
            label={__('Tier', 'yayloyalty')}
            size="lg"
            value={tier ? <TierBadge name={tier.name} color={tier.color} /> : __('No tier', 'yayloyalty')}
            subLine={
              tier && achievedAt
                ? sprintf(
                    /* translators: %s: the date the customer reached the tier. */
                    __('Since %s', 'yayloyalty'),
                    formatStoredDate(achievedAt),
                  )
                : undefined
            }
          />
          <div className="flex min-w-0 flex-col gap-2">
            <CustomerDrawerStat
              label={__('Next tier', 'yayloyalty')}
              value={
                next
                  ? sprintf(
                      /* translators: 1: points still to earn, 2: the next tier's name. */
                      __('%1$s pts to %2$s', 'yayloyalty'),
                      formatPoints(Math.max(0, next.threshold - progress)),
                      next.name,
                    )
                  : __('Top tier', 'yayloyalty')
              }
            />
            {next && (
              <div className="flex flex-col gap-1">
                <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                  <div
                    className="bg-primary h-full rounded-full"
                    style={{ width: `${progressPercent(progress, next.threshold)}%` }}
                  />
                </div>
                <span className="text-muted-foreground text-xs tabular-nums">
                  {sprintf(
                    /* translators: 1: points earned, 2: points the next tier needs. */
                    __('%1$s / %2$s', 'yayloyalty'),
                    formatPoints(progress),
                    formatPoints(next.threshold),
                  )}
                </span>
              </div>
            )}
          </div>
        </div>
        <div className="flex flex-col gap-2 border-t px-4 py-3">
          {manual && (
            <span className="text-muted-foreground flex flex-wrap items-center gap-1 text-xs">
              {__('Manual tier:', 'yayloyalty')}
              <TierBadge name={manual.name} color={manual.color} />
              {manual.until
                ? sprintf(
                    /* translators: %s: the date the manual tier ends. */
                    __('· until %s', 'yayloyalty'),
                    formatStoredDate(manual.until),
                  )
                : __('· no end', 'yayloyalty')}
            </span>
          )}
          <div className="flex flex-wrap gap-2">
            <Can cap={CAP.MANAGE_TIERS} disable>
              <Button variant="outline" size="sm" disabled={isDeleted} onClick={() => setDialog('assign')}>
                {__('Assign tier', 'yayloyalty')}
              </Button>
            </Can>
            {manual && (
              <Can cap={CAP.MANAGE_TIERS} disable>
                <Button variant="outline" size="sm" disabled={isDeleted} onClick={() => setDialog('remove')}>
                  {__('Remove manual tier', 'yayloyalty')}
                </Button>
              </Can>
            )}
          </div>
        </div>
      </div>
      {isDeleted && <p className="text-muted-foreground text-xs">{DELETED_ACCOUNT_ACTION_REASON()}</p>}

      {dialog === 'assign' && (
        <CustomerTierAssignDialog customer={customer} current={query.data} onClose={() => setDialog(undefined)} />
      )}
      <CustomerTierRemoveDialog
        customer={customer}
        open={dialog === 'remove'}
        onClose={() => setDialog(undefined)}
      />
    </div>
  );
}

/** The bar's fill, 0–100. A next tier at 0 points is already reached. */
function progressPercent(progress: number, threshold: number): number {
  return threshold <= 0 ? 100 : Math.min(100, Math.max(0, (progress / threshold) * 100));
}

/** Sets a manual floor. Mounted only while open, so each open starts from the defaults. */
function CustomerTierAssignDialog({
  customer,
  current,
  onClose,
}: {
  customer: Customer;
  current: CustomerTier;
  onClose: () => void;
}) {
  const tiersQuery = useTiersQuery();
  const assign = useAssignCustomerTierMutation(customer.user_id);
  const form = useForm<CustomerTierAssignValues>({
    resolver: zodResolver(customerTierAssignSchema()),
    defaultValues: CUSTOMER_TIER_ASSIGN_DEFAULTS,
  });
  const [tierId, end, days] = useWatch({ control: form.control, name: ['tier_id', 'end', 'days'] });
  const errors = form.formState.errors;
  const tiers = tiersQuery.data ?? [];
  const chosen = tiers.find((tier) => String(tier.id) === tierId);
  const currentRank = current.tier?.tier_rank ?? 0;

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await assign.mutateAsync(toAssignCustomerTierInput(values));
      toast.success(
        sprintf(
          /* translators: 1: the tier's name, 2: the customer's name. */
          __('%1$s assigned to %2$s.', 'yayloyalty'),
          chosen?.name ?? '',
          customer.name,
        ),
      );
      onClose();
    } catch (error) {
      await toastApiError(error, __('Could not assign the tier.', 'yayloyalty'));
    }
  });

  return (
    <Dialog open onOpenChange={(open) => !open && !assign.isPending && onClose()}>
      <DialogContent showCloseButton={!assign.isPending}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <DialogHeader>
            <DialogTitle>{__('Assign tier', 'yayloyalty')}</DialogTitle>
            <DialogDescription>
              {sprintf(
                /* translators: %s: the customer's name. */
                __('%s is kept at this tier or above. Their earned points can still move them higher.', 'yayloyalty'),
                customer.name,
              )}
            </DialogDescription>
          </DialogHeader>

          <Field data-invalid={!!errors.tier_id}>
            <FieldLabel htmlFor="yayloyalty-assign-tier">{__('Tier', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Controller
                control={form.control}
                name="tier_id"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange} disabled={tiersQuery.isPending}>
                    <SelectTrigger id="yayloyalty-assign-tier" className="w-full" aria-invalid={!!errors.tier_id}>
                      <SelectValue placeholder={__('Choose a tier', 'yayloyalty')} />
                    </SelectTrigger>
                    <SelectContent>
                      {tiers.map((tier) => (
                        <SelectItem key={tier.id} value={String(tier.id)}>
                          <TierBadge name={tier.name} color={tier.color} />
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              {errors.tier_id && <FieldError>{errors.tier_id.message}</FieldError>}
              {chosen && chosen.tier_rank <= currentRank && (
                <p className="text-muted-foreground text-xs">
                  {sprintf(
                    /* translators: %s: the customer's name. */
                    __('%s is already at or above this tier. This only stops them dropping below it.', 'yayloyalty'),
                    customer.name,
                  )}
                </p>
              )}
              {chosen && chosen.tier_rank > currentRank && chosen.entry_bonus > 0 && (
                <p className="text-muted-foreground text-xs">
                  {sprintf(
                    /* translators: %s: the tier's name. */
                    __("Pays the %s entry bonus once, if they haven't had it.", 'yayloyalty'),
                    chosen.name,
                  )}
                </p>
              )}
            </FieldContent>
          </Field>

          <Field data-invalid={!!errors.days}>
            <FieldLabel>{__('End', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Controller
                control={form.control}
                name="end"
                render={({ field }) => (
                  <RadioGroup className="gap-2.5" value={field.value} onValueChange={field.onChange}>
                    <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                      <RadioGroupItem value="none" />
                      {__('No end', 'yayloyalty')}
                    </label>
                    <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                      <RadioGroupItem value="limited" />
                      {__('Limited', 'yayloyalty')}
                    </label>
                  </RadioGroup>
                )}
              />
              {end === 'limited' && (
                <div className="flex flex-wrap items-center gap-3 pl-6.5">
                  <Controller
                    control={form.control}
                    name="days"
                    render={({ field }) => (
                      <NumberInputRoot
                        size="small"
                        className="w-32"
                        min={1}
                        max={MANUAL_TIER_DAYS_MAX}
                        value={field.value}
                        onValueChange={field.onChange}
                      >
                        <NumberInputInput aria-label={__('Days', 'yayloyalty')} aria-invalid={!!errors.days} />
                        <NumberInputUnit unit={__('days', 'yayloyalty')} />
                      </NumberInputRoot>
                    )}
                  />
                  {Number.isInteger(days) && days >= 1 && days <= MANUAL_TIER_DAYS_MAX && (
                    <span className="text-muted-foreground text-xs">
                      {sprintf(
                        /* translators: %s: the date the manual tier ends. */
                        __('Ends on %s', 'yayloyalty'),
                        manualTierEndsOn(days),
                      )}
                    </span>
                  )}
                </div>
              )}
              {errors.days && <FieldError>{errors.days.message}</FieldError>}
            </FieldContent>
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" disabled={assign.isPending} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <LoadingButton type="submit" loading={assign.isPending}>
              {__('Assign', 'yayloyalty')}
            </LoadingButton>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Confirms removing the manual floor. */
function CustomerTierRemoveDialog({
  customer,
  open,
  onClose,
}: {
  customer: Customer;
  open: boolean;
  onClose: () => void;
}) {
  const remove = useRemoveCustomerTierMutation(customer.user_id);

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => !next && !remove.isPending && onClose()}
      title={__('Remove manual tier?', 'yayloyalty')}
      description={sprintf(
        /* translators: %s: the customer's name. */
        __('%s goes back to the tier their earned points qualify for, which may be lower.', 'yayloyalty'),
        customer.name,
      )}
      confirmLabel={__('Remove', 'yayloyalty')}
      cancelLabel={__('Cancel', 'yayloyalty')}
      isLoading={remove.isPending}
      onConfirm={() =>
        remove.mutate(undefined, {
          onSuccess: () => {
            toast.success(__('Manual tier removed.', 'yayloyalty'));
            onClose();
          },
          onError: (error) => toastApiError(error, __('Could not remove the manual tier.', 'yayloyalty')),
        })
      }
    />
  );
}
