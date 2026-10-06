import { useState } from 'react';
import { useFormContext } from 'react-hook-form';
import { toast } from 'sonner';

import { toastApiError } from '@/lib/api/api';
import { useResetDesignMutation } from '@/lib/queries/design.queries';
import type { Design } from '@/lib/schema/design.schema';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
  CANCEL_LABEL,
  RESET_CONFIRM_LABEL,
  RESET_ERROR_FALLBACK,
  STOREFRONT_BALANCE_HEADING_LABEL,
  STOREFRONT_EARN_RATE_LINE_LABEL,
  STOREFRONT_LAUNCHER_LABEL_LABEL,
  STOREFRONT_REDEEM_BUTTON_LABEL,
  STOREFRONT_REQUIRED_ERROR,
  STOREFRONT_RESET_BUTTON_LABEL,
  STOREFRONT_RESET_CONFIRM_DESCRIPTION,
  STOREFRONT_RESET_CONFIRM_TITLE,
  STOREFRONT_RESET_SUCCESS_TOAST,
  STOREFRONT_SECTION_DESCRIPTION,
  STOREFRONT_SECTION_TITLE,
  STOREFRONT_SIGNED_OUT_PROMPT_LABEL,
} from './design-settings.copy';

type StorefrontTextKey = keyof Design['storefront_text'];

/** One storefront-text input with its label and, for the required labels, the blank-field error. */
function StorefrontTextField({ name, label }: { name: StorefrontTextKey; label: string }) {
  const { register, formState } = useFormContext<Design>();
  const id = `design-storefront-${name.replaceAll('_', '-')}`;
  const invalid = !!formState.errors.storefront_text?.[name];

  return (
    <Field data-invalid={invalid}>
      <FieldLabel htmlFor={id} className="items-center">
        {label}
      </FieldLabel>
      <FieldContent>
        <Input id={id} aria-invalid={invalid} {...register(`storefront_text.${name}`)} />
        {invalid && <FieldError>{STOREFRONT_REQUIRED_ERROR()}</FieldError>}
      </FieldContent>
    </Field>
  );
}

/**
 * Five text inputs, pre-filled with the stored wording rather than shown
 * as placeholders, so an empty field means empty.
 */
export function DesignStorefrontTextSection() {
  const { setValue } = useFormContext<Design>();
  const resetMutation = useResetDesignMutation();
  const [confirmOpen, setConfirmOpen] = useState(false);

  async function handleReset() {
    try {
      const returned = await resetMutation.mutateAsync('storefront_text');

      // Group-scoped setValue loop, never a whole form.reset(): a full reset here would
      // discard the merchant's unsaved edits in other sections as a side effect.
      (Object.entries(returned.storefront_text) as [StorefrontTextKey, string][]).forEach(([key, value]) => {
        setValue(`storefront_text.${key}`, value, { shouldDirty: false });
      });

      toast.success(STOREFRONT_RESET_SUCCESS_TOAST());
      setConfirmOpen(false);
    } catch (error) {
      await toastApiError(error, RESET_ERROR_FALLBACK());
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{STOREFRONT_SECTION_TITLE()}</CardTitle>
        <CardDescription>{STOREFRONT_SECTION_DESCRIPTION()}</CardDescription>
        <CardAction>
          <Button type="button" variant="outline" size="sm" onClick={() => setConfirmOpen(true)}>
            {STOREFRONT_RESET_BUTTON_LABEL()}
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <StorefrontTextField name="launcher_label" label={STOREFRONT_LAUNCHER_LABEL_LABEL()} />
        <StorefrontTextField name="balance_heading" label={STOREFRONT_BALANCE_HEADING_LABEL()} />
        <StorefrontTextField name="earn_rate_line" label={STOREFRONT_EARN_RATE_LINE_LABEL()} />
        <StorefrontTextField name="redeem_button" label={STOREFRONT_REDEEM_BUTTON_LABEL()} />
        <StorefrontTextField name="signed_out_prompt" label={STOREFRONT_SIGNED_OUT_PROMPT_LABEL()} />
      </CardContent>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={STOREFRONT_RESET_CONFIRM_TITLE()}
        description={STOREFRONT_RESET_CONFIRM_DESCRIPTION()}
        confirmLabel={RESET_CONFIRM_LABEL()}
        cancelLabel={CANCEL_LABEL()}
        variant="destructive"
        isLoading={resetMutation.isPending}
        onConfirm={handleReset}
      />
    </Card>
  );
}
