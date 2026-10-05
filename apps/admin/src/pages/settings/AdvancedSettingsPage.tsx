import { zodResolver } from '@hookform/resolvers/zod';
import { FormProvider, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useSaveSettingsMutation, useSettingsQuery } from '@/lib/queries/settings.queries';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { ProGate } from '@/components/pro/ProGate';
import { AdvancedFraudLimitsSection } from '@/pro';
import { FRAUD_LIMITS_TITLE, FRAUD_LIMITS_UPSELL } from './advanced-settings.copy';
import {
  advancedSettingsDefaults,
  advancedSettingsFormSchema,
  AdvancedSettingsFormValues,
  buildAdvancedSettingsUpdate,
} from './advanced-settings.form';
import { AdvancedPointsNamingCard } from './AdvancedPointsNamingCard';

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

/**
 * Settings → Advanced: one form and one Save for every card. Saves only the edited fields, so an
 * untouched label is never stored and keeps following the site language.
 */
export default function AdvancedSettingsPage() {
  const settingsQuery = useSettingsQuery();
  const saveMutation = useSaveSettingsMutation();

  // `values` + keepDirtyValues: a refetch re-seeds the form without clobbering a field mid-edit.
  const form = useForm<AdvancedSettingsFormValues>({
    resolver: zodResolver(advancedSettingsFormSchema),
    values: advancedSettingsDefaults(settingsQuery.data),
    resetOptions: { keepDirtyValues: true },
  });

  const { showDialog, confirmLeave, cancelLeave } = useRouteLeaveGuard(form.formState.isDirty);

  const handleSave = form.handleSubmit(async (values) => {
    try {
      const saved = await saveMutation.mutateAsync(buildAdvancedSettingsUpdate(values, form.formState.dirtyFields));
      // Overrides resetOptions, or keepDirtyValues would keep the edit over the sanitised save.
      form.reset(advancedSettingsDefaults(saved), { keepDirtyValues: false });
      confirmLeave();
      toast.success(__('Advanced settings saved.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Failed to save advanced settings.', 'yayloyalty'));
    }
  });

  return (
    <FormProvider {...form}>
      <form onSubmit={handleSave} className="flex flex-col gap-4">
        <AdvancedPointsNamingCard />
        {IS_PRO_BUILD ? <AdvancedFraudLimitsSection /> : <FraudLimitsUpsellCard />}

        <div className="flex items-center justify-end gap-2">
          <Button type="submit" disabled={!form.formState.isDirty || saveMutation.isPending}>
            {__('Save changes', 'yayloyalty')}
          </Button>
        </div>
      </form>

      <UnsavedChangeDialog
        open={showDialog}
        onDiscard={confirmLeave}
        onSave={handleSave}
        onOpenChange={(open) => {
          if (!open) {
            cancelLeave();
          }
        }}
      />
    </FormProvider>
  );
}
