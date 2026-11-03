import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { formatStoreNumber } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { Tier, TIER_LIMITS, TierInUseReward } from '@/lib/schema/tier.schema';
import { Button, LoadingButton } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ColorPicker } from '@/components/ui/color-picker';
import { PointsInput } from '@/components/ui/custom/amount-inputs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { ErrorState } from '@/components/ui/custom/error-state';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EllipsisIcon from '@/components/icons/EllipsisIcon';
import { newTierValues, tierFormSchema, TierFormValues, tierFormValues, toTierInput } from './tier-catalog.form';
import { readTierInUse } from './tiers.api';
import { useCreateTierMutation, useDeleteTierMutation, useTiersQuery, useUpdateTierMutation } from './tiers.queries';

/** Which dialog is open: a new tier, or a snapshot of the row being edited, so a refetch can't swap it. */
type FormTarget = { mode: 'create' } | { mode: 'edit'; tier: Tier };

const tierLimitMessage = () =>
  sprintf(
    /* translators: %s: the most tiers a store may have. */
    __('You can have at most %s tiers.', 'yayloyalty'),
    formatStoreNumber(TIER_LIMITS.maxLive, 0),
  );

/**
 * Pro's Tiers screen: the live tiers in threshold order, with the create, edit and delete dialogs.
 * Rank follows threshold, so there is no drag; changing a threshold moves the row.
 */
export function TierCatalog() {
  const { data: tiers, isPending, isError, refetch } = useTiersQuery();
  const [formTarget, setFormTarget] = useState<FormTarget>();
  const [deleteTarget, setDeleteTarget] = useState<Tier>();

  const atLimit = (tiers?.length ?? 0) >= TIER_LIMITS.maxLive;
  const addButton = (
    <Button disabled={isPending || isError || atLimit} onClick={() => setFormTarget({ mode: 'create' })}>
      {__('Add tier', 'yayloyalty')}
    </Button>
  );

  return (
    <Card>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-muted-foreground flex-1 text-sm">
            {__(
              'Customers move up a tier as they earn points. Each tier multiplies the points they earn.',
              'yayloyalty',
            )}
          </p>
          {atLimit ? (
            <YayLoyaltyToolTip
              content={tierLimitMessage()}
              trigger={<span className="inline-flex">{addButton}</span>}
            />
          ) : (
            addButton
          )}
        </div>

        {isError && <ErrorState message={__('Could not load tiers.', 'yayloyalty')} onRetry={() => refetch()} />}

        {!isError && (
          <div className="overflow-hidden rounded-lg border">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{__('Tier', 'yayloyalty')}</TableHead>
                    <TableHead className="text-right">{__('Points to reach', 'yayloyalty')}</TableHead>
                    <TableHead className="text-right">{__('Multiplier', 'yayloyalty')}</TableHead>
                    <TableHead className="text-right">{__('Entry bonus', 'yayloyalty')}</TableHead>
                    <TableHead className="text-right">{__('Members', 'yayloyalty')}</TableHead>
                    <TableHead className="w-12" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isPending
                    ? [0, 1, 2].map((key) => <TierRowSkeleton key={key} />)
                    : tiers.map((tier) => (
                        <TierRow
                          key={tier.id}
                          tier={tier}
                          onEdit={() => setFormTarget({ mode: 'edit', tier })}
                          onDelete={() => setDeleteTarget(tier)}
                        />
                      ))}
                </TableBody>
              </Table>
            </div>
          </div>
        )}
      </CardContent>

      {formTarget && tiers && (
        <TierFormDialog target={formTarget} tiers={tiers} onClose={() => setFormTarget(undefined)} />
      )}

      {deleteTarget && <TierDeleteDialog tier={deleteTarget} onClose={() => setDeleteTarget(undefined)} />}
    </Card>
  );
}

function TierRow({ tier, onEdit, onDelete }: { tier: Tier; onEdit: () => void; onDelete: () => void }) {
  return (
    <TableRow>
      <TableCell>
        <span className="flex items-center gap-2 font-medium">
          <span className="size-3 shrink-0 rounded-full border" style={{ backgroundColor: tier.color }} aria-hidden />
          {tier.name}
        </span>
      </TableCell>
      <TableCell className="text-right tabular-nums">{formatPoints(tier.threshold)}</TableCell>
      <TableCell className="text-right tabular-nums">
        {sprintf(
          /* translators: %s: the tier's points multiplier, such as 1.25. */
          __('×%s', 'yayloyalty'),
          formatStoreNumber(Number(tier.multiplier), 2),
        )}
      </TableCell>
      <TableCell className="text-right tabular-nums">{formatPoints(tier.entry_bonus)}</TableCell>
      <TableCell className="text-right tabular-nums">{formatStoreNumber(tier.member_count, 0)}</TableCell>
      <TableCell>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className="text-muted-foreground mx-auto flex size-6 items-center justify-center"
              aria-label={sprintf(
                /* translators: %s: the tier's name. */
                __('“%s” actions', 'yayloyalty'),
                tier.name,
              )}
            >
              <EllipsisIcon />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={onEdit}>{__('Edit', 'yayloyalty')}</DropdownMenuItem>
            <DropdownMenuItem variant="destructive" onSelect={onDelete}>
              {__('Delete', 'yayloyalty')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  );
}

function TierRowSkeleton() {
  return (
    <TableRow className="hover:bg-transparent">
      <TableCell>
        <Skeleton className="h-4 w-28" />
      </TableCell>
      {[0, 1, 2, 3].map((key) => (
        <TableCell key={key}>
          <Skeleton className="ml-auto h-4 w-12" />
        </TableCell>
      ))}
      <TableCell />
    </TableRow>
  );
}

/** Create or edit one tier. The dialog stays open with the typed values when the server refuses the save. */
function TierFormDialog({ target, tiers, onClose }: { target: FormTarget; tiers: Tier[]; onClose: () => void }) {
  const isEdit = target.mode === 'edit';
  const form = useForm<TierFormValues>({
    resolver: zodResolver(tierFormSchema()),
    defaultValues: isEdit ? tierFormValues(target.tier) : newTierValues(tiers),
  });
  const createTier = useCreateTierMutation();
  const updateTier = useUpdateTierMutation();
  const pending = createTier.isPending || updateTier.isPending;
  const errors = form.formState.errors;

  const onSubmit = form.handleSubmit(async (values) => {
    const input = toTierInput(values);

    try {
      if (isEdit) {
        await updateTier.mutateAsync({ id: target.tier.id, input });
      } else {
        await createTier.mutateAsync(input);
      }

      toast.success(
        sprintf(
          /* translators: %s: the tier's name. */
          isEdit ? __('“%s” saved.', 'yayloyalty') : __('“%s” added.', 'yayloyalty'),
          input.name,
        ),
      );
      onClose();
    } catch (error) {
      await toastApiError(error, __('Could not save the tier.', 'yayloyalty'));
    }
  });

  return (
    <Dialog open onOpenChange={(open) => !open && !pending && onClose()}>
      <DialogContent showCloseButton={!pending}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <DialogHeader>
            <DialogTitle>{isEdit ? __('Edit tier', 'yayloyalty') : __('Add tier', 'yayloyalty')}</DialogTitle>
            <DialogDescription>
              {__('Customers reach this tier once the points they have earned reach its threshold.', 'yayloyalty')}
            </DialogDescription>
          </DialogHeader>

          <Field data-invalid={!!errors.name}>
            <FieldLabel htmlFor="yayloyalty-tier-name">{__('Name', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Input
                id="yayloyalty-tier-name"
                maxLength={TIER_LIMITS.nameMax}
                aria-invalid={!!errors.name}
                {...form.register('name')}
              />
              {errors.name && <FieldError>{errors.name.message}</FieldError>}
            </FieldContent>
          </Field>

          <Field data-invalid={!!errors.threshold}>
            <FieldLabel>{__('Points to reach', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Controller
                control={form.control}
                name="threshold"
                render={({ field }) => <PointsInput value={field.value} min={0} onValueChange={field.onChange} />}
              />
              {errors.threshold && <FieldError>{errors.threshold.message}</FieldError>}
            </FieldContent>
          </Field>

          <Field data-invalid={!!errors.multiplier}>
            <FieldLabel>{__('Points multiplier', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Controller
                control={form.control}
                name="multiplier"
                render={({ field }) => (
                  <NumberInputRoot
                    size="small"
                    className="w-32"
                    min={TIER_LIMITS.multiplierMin}
                    max={TIER_LIMITS.multiplierMax}
                    step={TIER_LIMITS.multiplierStep}
                    decimalScale={2}
                    fixedDecimalScale
                    value={field.value}
                    onValueChange={field.onChange}
                  >
                    <NumberInputInput />
                    <NumberInputUnit unit="×" />
                  </NumberInputRoot>
                )}
              />
              {errors.multiplier && <FieldError>{errors.multiplier.message}</FieldError>}
            </FieldContent>
          </Field>

          <Field data-invalid={!!errors.entry_bonus}>
            <FieldLabel>{__('Entry bonus', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Controller
                control={form.control}
                name="entry_bonus"
                render={({ field }) => <PointsInput value={field.value} min={0} onValueChange={field.onChange} />}
              />
              {errors.entry_bonus && <FieldError>{errors.entry_bonus.message}</FieldError>}
            </FieldContent>
          </Field>

          <Field data-invalid={!!errors.color}>
            <FieldLabel>{__('Badge colour', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Controller
                control={form.control}
                name="color"
                render={({ field }) => <ColorPicker value={field.value} onValueChange={field.onChange} />}
              />
              {errors.color && <FieldError>{errors.color.message}</FieldError>}
            </FieldContent>
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" disabled={pending} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <LoadingButton type="submit" loading={pending}>
              {isEdit ? __('Save', 'yayloyalty') : __('Add tier', 'yayloyalty')}
            </LoadingButton>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Confirms a delete. When rewards still require the tier, it lists them instead and offers no delete. */
function TierDeleteDialog({ tier, onClose }: { tier: Tier; onClose: () => void }) {
  const deleteTier = useDeleteTierMutation();
  const [blockers, setBlockers] = useState<TierInUseReward[]>();

  async function confirm() {
    try {
      await deleteTier.mutateAsync(tier.id);
      toast.success(
        sprintf(
          /* translators: %s: the tier's name. */
          __('“%s” deleted.', 'yayloyalty'),
          tier.name,
        ),
      );
      onClose();
    } catch (error) {
      const rewards = await readTierInUse(error);

      if (rewards) {
        setBlockers(rewards);
      } else {
        await toastApiError(error, __('Could not delete the tier.', 'yayloyalty'));
      }
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !deleteTier.isPending && onClose()}>
      <DialogContent showCloseButton={!deleteTier.isPending}>
        <DialogHeader>
          <DialogTitle>
            {sprintf(
              /* translators: %s: the tier's name. */
              __('Delete “%s”?', 'yayloyalty'),
              tier.name,
            )}
          </DialogTitle>
          <DialogDescription>
            {blockers
              ? __('These rewards require this tier. Remove the tier from them before deleting it.', 'yayloyalty')
              : __('The tier leaves the catalogue. Its history stays in each customer’s activity.', 'yayloyalty')}
          </DialogDescription>
        </DialogHeader>

        {blockers && (
          <ul className="list-disc pl-5 text-sm">
            {blockers.map((reward) => (
              <li key={reward.id}>{reward.name}</li>
            ))}
          </ul>
        )}

        <DialogFooter>
          <Button variant="outline" disabled={deleteTier.isPending} onClick={onClose}>
            {blockers ? __('Close', 'yayloyalty') : __('Cancel', 'yayloyalty')}
          </Button>
          {!blockers && (
            <LoadingButton variant="destructive" loading={deleteTier.isPending} onClick={confirm}>
              {__('Delete', 'yayloyalty')}
            </LoadingButton>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
