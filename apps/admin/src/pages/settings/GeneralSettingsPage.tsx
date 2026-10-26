import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, FormProvider, useForm, useFormContext } from 'react-hook-form';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useSaveSettingsMutation, useSettingsQuery } from '@/lib/queries/settings.queries';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { ProGate } from '@/components/pro/ProGate';
import { FraudLimitsSection } from '@/pro';
import {
  FRAUD_LIMITS_TITLE,
  FRAUD_LIMITS_UPSELL,
  YAYCURRENCY_DESCRIPTION,
  YAYCURRENCY_INCLUDE_FEE,
  YAYCURRENCY_INCLUDE_FEE_HINT,
  YAYCURRENCY_TITLE,
} from './general-settings.copy';
import {
  buildGeneralSettingsUpdate,
  generalSettingsDefaults,
  generalSettingsFormSchema,
  GeneralSettingsFormValues,
} from './general-settings.form';
import { PointsNamingCard } from './PointsNamingCard';

/** Lite's place for the fraud limits: the same card as an upsell, with no input. */
function FraudLimitsUpsellCard() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{FRAUD_LIMITS_TITLE()}</CardTitle>
        <CardDescription>{FRAUD_LIMITS_UPSELL()}</CardDescription>
      </CardHeader>
      <CardContent>
        <ProGate />
      </CardContent>
    </Card>
  );
}

/** Shown in both builds while YayCurrency is active: whether its exchange fee is part of a point's rate. */
function YayCurrencyCard() {
  const { control } = useFormContext<GeneralSettingsFormValues>();

  return (
    <Card>
      <CardHeader>
        <CardTitle>{YAYCURRENCY_TITLE()}</CardTitle>
        <CardDescription>{YAYCURRENCY_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent>
        <Field orientation="horizontal">
          <FieldContent>
            <FieldLabel htmlFor="currency_include_fee">{YAYCURRENCY_INCLUDE_FEE()}</FieldLabel>
            <FieldDescription>{YAYCURRENCY_INCLUDE_FEE_HINT()}</FieldDescription>
          </FieldContent>
          <Controller
            control={control}
            name="currency_include_fee"
            render={({ field }) => (
              <Switch
                id="currency_include_fee"
                className="w-11!"
                checked={field.value}
                onCheckedChange={field.onChange}
              />
            )}
          />
        </Field>
      </CardContent>
    </Card>
  );
}

/**
 * Settings → General: one form and one Save for every card. Saves only the edited fields, so an
 * untouched label is never stored and keeps following the site language.
 */
export default function GeneralSettingsPage() {
  const settingsQuery = useSettingsQuery();
  const saveMutation = useSaveSettingsMutation();

  // `values` + keepDirtyValues: a refetch re-seeds the form without clobbering a field mid-edit.
  const form = useForm<GeneralSettingsFormValues>({
    resolver: zodResolver(generalSettingsFormSchema),
    values: generalSettingsDefaults(settingsQuery.data),
    resetOptions: { keepDirtyValues: true },
  });

  const { handleSave, leaveDialogProps } = useEditorSave(
    form,
    async (values) => {
      try {
        const saved = await saveMutation.mutateAsync(buildGeneralSettingsUpdate(values, form.formState.dirtyFields));
        // Overrides resetOptions, or keepDirtyValues would keep the edit over the sanitised save.
        form.reset(generalSettingsDefaults(saved), { keepDirtyValues: false });
        toast.success(__('General settings saved.', 'yayloyalty'));
        return true;
      } catch (error) {
        await toastApiError(error, __('Failed to save general settings.', 'yayloyalty'));
        return false;
      }
    },
    { listPath: null },
  );

  return (
    <FormProvider {...form}>
      <form onSubmit={handleSave} className="flex flex-col gap-4">
        <PointsNamingCard />
        {IS_PRO_BUILD ? <FraudLimitsSection /> : <FraudLimitsUpsellCard />}
        {window.yayLoyaltyMeta.wcMeta.yaycurrency_active && <YayCurrencyCard />}

        <div className="flex items-center justify-end gap-2">
          <Button type="submit" disabled={!form.formState.isDirty || saveMutation.isPending}>
            {__('Save changes', 'yayloyalty')}
          </Button>
        </div>
      </form>

      <UnsavedChangeDialog {...leaveDialogProps} />
    </FormProvider>
  );
}
