import { useState } from 'react';
import { HTTPError } from 'ky';
import { useFormContext } from 'react-hook-form';
import { toast } from 'sonner';

import { getApiErrorMessage } from '@/lib/api/api';
import { useResetDesignMutation } from '@/lib/queries/design.queries';
import type { Design } from '@/lib/schema/design.schema';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ProGate } from '@/components/pro/ProGate';
import {
  CANCEL_LABEL,
  RESET_CONFIRM_LABEL,
  RESET_ERROR_FALLBACK,
  STOREFRONT_BALANCE_HEADING_LABEL,
  STOREFRONT_EARN_RATE_LINE_LABEL,
  STOREFRONT_LAUNCHER_LABEL_LABEL,
  STOREFRONT_REDEEM_BUTTON_LABEL,
  STOREFRONT_REFERRAL_CARD_HEADING_LABEL,
  STOREFRONT_RESET_BUTTON_LABEL,
  STOREFRONT_RESET_CONFIRM_DESCRIPTION,
  STOREFRONT_RESET_CONFIRM_TITLE,
  STOREFRONT_RESET_SUCCESS_TOAST,
  STOREFRONT_SECTION_DESCRIPTION,
  STOREFRONT_SECTION_TITLE,
  STOREFRONT_SIGNED_OUT_PROMPT_LABEL,
  STOREFRONT_TIER_PROGRESS_LINE_LABEL,
} from './design-settings.copy';

/**
 * Five Free text inputs, pre-filled with the server's values — no
 * placeholder affordance (phase 01, Key Insight 6). The two Pro fields are
 * plain inputs gated in place with IS_PRO_BUILD + ProGate inline (Key
 * Insight 5), not moved to a whole Pro section.
 */
export function DesignStorefrontTextSection() {
  const { register, setValue } = useFormContext<Design>();
  const resetMutation = useResetDesignMutation();
  const [confirmOpen, setConfirmOpen] = useState(false);

  async function handleReset() {
    try {
      const returned = await resetMutation.mutateAsync('storefront_text');

      // Group-scoped setValue loop, never a whole form.reset(): a full reset here would
      // discard the merchant's unsaved edits in other sections as a side effect.
      (Object.entries(returned.storefront_text) as [keyof Design['storefront_text'], string][]).forEach(
        ([key, value]) => {
          setValue(`storefront_text.${key}`, value, { shouldDirty: false });
        },
      );

      toast.success(STOREFRONT_RESET_SUCCESS_TOAST());
      setConfirmOpen(false);
    } catch (error) {
      if (!(error instanceof HTTPError && error.response.status === 403)) {
        toast.error(await getApiErrorMessage(error, RESET_ERROR_FALLBACK()));
      }
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
        <Field>
          <FieldLabel htmlFor="design-storefront-launcher-label">{STOREFRONT_LAUNCHER_LABEL_LABEL()}</FieldLabel>
          <FieldContent>
            <Input id="design-storefront-launcher-label" {...register('storefront_text.launcher_label')} />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-storefront-balance-heading">{STOREFRONT_BALANCE_HEADING_LABEL()}</FieldLabel>
          <FieldContent>
            <Input id="design-storefront-balance-heading" {...register('storefront_text.balance_heading')} />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-storefront-earn-rate-line">{STOREFRONT_EARN_RATE_LINE_LABEL()}</FieldLabel>
          <FieldContent>
            <Input id="design-storefront-earn-rate-line" {...register('storefront_text.earn_rate_line')} />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-storefront-redeem-button">{STOREFRONT_REDEEM_BUTTON_LABEL()}</FieldLabel>
          <FieldContent>
            <Input id="design-storefront-redeem-button" {...register('storefront_text.redeem_button')} />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-storefront-signed-out-prompt">
            {STOREFRONT_SIGNED_OUT_PROMPT_LABEL()}
          </FieldLabel>
          <FieldContent>
            <Input id="design-storefront-signed-out-prompt" {...register('storefront_text.signed_out_prompt')} />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-storefront-referral-card-heading" className="items-center">
            {STOREFRONT_REFERRAL_CARD_HEADING_LABEL()}
            {!IS_PRO_BUILD && <ProGate inline />}
          </FieldLabel>
          <FieldContent>
            {IS_PRO_BUILD && (
              <Input
                id="design-storefront-referral-card-heading"
                {...register('storefront_text.referral_card_heading')}
              />
            )}
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-storefront-tier-progress-line" className="items-center">
            {STOREFRONT_TIER_PROGRESS_LINE_LABEL()}
            {!IS_PRO_BUILD && <ProGate inline />}
          </FieldLabel>
          <FieldContent>
            {IS_PRO_BUILD && (
              <Input id="design-storefront-tier-progress-line" {...register('storefront_text.tier_progress_line')} />
            )}
          </FieldContent>
        </Field>
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
