import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useSaveSettingsMutation, useSettingsQuery } from '@/lib/queries/settings.queries';
import { settingsSchema } from '@/lib/schema/settings.schema';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { Label } from '@/components/ui/label';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { FRAUD_LIMITS_DESCRIPTION, FRAUD_LIMITS_TITLE } from '@/pages/settings/advanced-settings.copy';

const fraudLimitsSchema = settingsSchema.shape.advanced.pick({ max_points_per_day: true });

type FraudLimitsValues = z.infer<typeof fraudLimitsSchema>;

const FIELD_ID = 'yayloyalty-max-points-per-day';

/**
 * Settings → Advanced "Fraud limits": the per-customer daily cap that Pro's DailyEarningLimit (PHP)
 * enforces at award time. Saves only its own field, so nothing else in the settings option is written.
 */
export function AdvancedFraudLimitsForm() {
  const settingsQuery = useSettingsQuery();
  const saveMutation = useSaveSettingsMutation();

  // `values` + keepDirtyValues: a refetch re-seeds the form without clobbering a field mid-edit.
  const form = useForm<FraudLimitsValues>({
    resolver: zodResolver(fraudLimitsSchema),
    values: { max_points_per_day: settingsQuery.data.advanced.max_points_per_day },
    resetOptions: { keepDirtyValues: true },
  });

  const { showDialog, confirmLeave, cancelLeave } = useRouteLeaveGuard(form.formState.isDirty);

  const handleSave = form.handleSubmit(async (values) => {
    try {
      const saved = await saveMutation.mutateAsync({ advanced: values });
      // Overrides resetOptions, or keepDirtyValues would keep the edit over the sanitised save.
      form.reset({ max_points_per_day: saved.advanced.max_points_per_day }, { keepDirtyValues: false });
      confirmLeave();
      toast.success(__('Advanced settings saved.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Failed to save advanced settings.', 'yayloyalty'));
    }
  });

  return (
    <>
      <form onSubmit={handleSave} className="flex flex-col gap-4">
        <Card className="gap-3">
          <CardHeader>
            <CardTitle>{FRAUD_LIMITS_TITLE()}</CardTitle>
            <CardDescription>{FRAUD_LIMITS_DESCRIPTION()}</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            <Label htmlFor={FIELD_ID}>{__('Max points per customer per day', 'yayloyalty')}</Label>
            <Controller
              control={form.control}
              name="max_points_per_day"
              render={({ field }) => (
                <NumberInputRoot className="w-40" min={0} value={field.value} onValueChange={field.onChange}>
                  <NumberInputInput id={FIELD_ID} />
                  <NumberInputUnit unit={__('points', 'yayloyalty')} />
                </NumberInputRoot>
              )}
            />
            <p className="text-muted-foreground text-xs">
              {__(
                'Earning above this is not awarded and is written to the log. Points already awarded are never removed. Set 0 for no limit.',
                'yayloyalty',
              )}
            </p>
          </CardContent>
        </Card>

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
    </>
  );
}
