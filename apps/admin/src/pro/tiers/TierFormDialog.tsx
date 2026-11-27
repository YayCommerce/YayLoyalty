import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { Tier, TIER_LIMITS } from '@/lib/schema/tier.schema';
import { Button, LoadingButton } from '@/components/ui/button';
import {
  ColorPicker,
  ColorPickerContent,
  ColorPickerInput,
  ColorPickerPanel,
  ColorPickerTrigger,
} from '@/components/ui/color-picker';
import { PointsInput } from '@/components/ui/custom/amount-inputs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { newTierValues, tierFormSchema, TierFormValues, tierFormValues, toTierInput } from './tier-catalog.form';
import { useCreateTierMutation, useUpdateTierMutation } from './tiers.queries';

/** Which dialog is open: a new tier, or a snapshot of the row being edited, so a refetch can't swap it. */
export type TierFormTarget = { mode: 'create' } | { mode: 'edit'; tier: Tier };

/** Create or edit one tier. The dialog stays open with the typed values when the server refuses the save. */
export function TierFormDialog({
  target,
  tiers,
  onClose,
}: {
  target: TierFormTarget;
  tiers: Tier[];
  onClose: () => void;
}) {
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
          <DialogHeader className="border-b pb-4">
            <DialogTitle>{isEdit ? __('Edit tier', 'yayloyalty') : __('Add tier', 'yayloyalty')}</DialogTitle>
            <DialogDescription>
              {__('Customers unlock this tier once they earn enough points.', 'yayloyalty')}
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field data-invalid={!!errors.name} className="sm:col-span-2">
              <FieldLabel htmlFor="yayloyalty-tier-name">{__('Name', 'yayloyalty')}</FieldLabel>
              <FieldContent>
                <Input
                  id="yayloyalty-tier-name"
                  placeholder={__('e.g. Gold', 'yayloyalty')}
                  maxLength={TIER_LIMITS.nameMax}
                  aria-invalid={!!errors.name}
                  {...form.register('name')}
                />
                {errors.name && <FieldError>{errors.name.message}</FieldError>}
              </FieldContent>
            </Field>

            <Field>
              <FieldLabel>{__('Badge colour', 'yayloyalty')}</FieldLabel>
              <FieldContent>
                <Controller
                  control={form.control}
                  name="color"
                  render={({ field }) => (
                    <ColorPicker value={field.value} onValueChange={field.onChange}>
                      <ColorPickerTrigger className="w-full" />
                      <ColorPickerContent>
                        <ColorPickerPanel />
                        <ColorPickerInput />
                      </ColorPickerContent>
                    </ColorPicker>
                  )}
                />
              </FieldContent>
            </Field>

            <Field data-invalid={!!errors.threshold}>
              <FieldLabel>{__('Points to reach', 'yayloyalty')}</FieldLabel>
              <FieldContent>
                <Controller
                  control={form.control}
                  name="threshold"
                  render={({ field }) => (
                    <PointsInput className="w-full" value={field.value} min={0} onValueChange={field.onChange} />
                  )}
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
                      className="w-full"
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
                  render={({ field }) => (
                    <PointsInput className="w-full" value={field.value} min={0} onValueChange={field.onChange} />
                  )}
                />
                {errors.entry_bonus && <FieldError>{errors.entry_bonus.message}</FieldError>}
              </FieldContent>
            </Field>
          </div>

          <DialogFooter className="border-t pt-4">
            <Button type="button" variant="outline" disabled={pending} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <LoadingButton type="submit" loading={pending}>
              {isEdit ? __('Save changes', 'yayloyalty') : __('Add tier', 'yayloyalty')}
            </LoadingButton>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
