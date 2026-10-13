import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { FormProvider, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useSaveSettingsMutation, useSettingsQuery } from '@/lib/queries/settings.queries';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Button } from '@/components/ui/button';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { ExpiryModelSection, ExpiryRemindersSection } from '@/pro';
import {
  buildExpirySettingsUpdate,
  expirySettingsDefaults,
  expirySettingsFormSchema,
  ExpirySettingsFormValues,
} from './expiry-settings.form';
import { ExpiryInactivityCard } from './ExpiryInactivityCard';
import { ExpiryProUpsellCard } from './ExpiryProUpsellCard';

/**
 * Settings → Expiry: one form and one Save. Saves only the edited fields of the `expiry` group; the server
 * then re-dates every customer, so the change reaches existing balances at once.
 */
export default function ExpirySettingsPage() {
  const settingsQuery = useSettingsQuery();
  const saveMutation = useSaveSettingsMutation();
  const queryClient = useQueryClient();
  const savedModel = settingsQuery.data.expiry.model;

  // `values` + keepDirtyValues: a refetch re-seeds the form without clobbering a field mid-edit.
  const form = useForm<ExpirySettingsFormValues>({
    resolver: zodResolver(expirySettingsFormSchema),
    values: expirySettingsDefaults(settingsQuery.data),
    resetOptions: { keepDirtyValues: true },
  });

  const { handleSave, leaveDialogProps } = useEditorSave(
    form,
    async (values) => {
      try {
        const saved = await saveMutation.mutateAsync(buildExpirySettingsUpdate(values, form.formState.dirtyFields));
        // Overrides resetOptions, or keepDirtyValues would keep the edit over the sanitised save.
        form.reset(expirySettingsDefaults(saved), { keepDirtyValues: false });
        // The save re-dated every customer, so the expiring count and any customer's expiry are stale.
        queryClient.invalidateQueries({ queryKey: ['customer'] });
        toast.success(__('Expiry settings saved.', 'yayloyalty'));
        return true;
      } catch (error) {
        await toastApiError(error, __('Failed to save expiry settings.', 'yayloyalty'));
        return false;
      }
    },
    { listPath: null },
  );

  return (
    <FormProvider {...form}>
      <form onSubmit={handleSave} className="flex flex-col gap-4">
        <ExpiryInactivityCard />
        {IS_PRO_BUILD ? <ExpiryModelSection savedModel={savedModel} /> : null}
        {IS_PRO_BUILD ? <ExpiryRemindersSection /> : <ExpiryProUpsellCard />}

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
