import { useRef, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { FormProvider, useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useSaveSettingsMutation, useSettingsQuery } from '@/lib/queries/settings.queries';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import {
  CONFIRM_BATCH_BODY,
  CONFIRM_BATCH_TITLE,
  CONFIRM_CANCEL,
  CONFIRM_SAVE,
  CONFIRM_SHORTEN_BODY,
  CONFIRM_SHORTEN_TITLE,
} from './expiry-settings.copy';
import {
  buildExpirySettingsUpdate,
  expirySettingsDefaults,
  expirySettingsFormSchema,
  ExpirySettingsFormValues,
  needsConfirmation,
} from './expiry-settings.form';
import { ExpiryInactivityCard } from './ExpiryInactivityCard';
import { ExpiryMoreInProCard } from './ExpiryMoreInProCard';

type Confirmation = NonNullable<ReturnType<typeof needsConfirmation>>;

/**
 * Settings → Expiry: one form and one Save. A save that can bring dates sooner, or switches to batch,
 * waits for the admin to confirm; Cancel leaves the form as edited.
 */
export default function ExpirySettingsPage() {
  const settingsQuery = useSettingsQuery();
  const saveMutation = useSaveSettingsMutation();
  const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
  // Settles the pending save: true when confirmed, false when cancelled.
  const settleRef = useRef<((confirmed: boolean) => void) | null>(null);

  const form = useForm<ExpirySettingsFormValues>({
    resolver: zodResolver(expirySettingsFormSchema),
    values: expirySettingsDefaults(settingsQuery.data),
    resetOptions: { keepDirtyValues: true },
  });

  const confirm = (kind: Confirmation) =>
    new Promise<boolean>((resolve) => {
      settleRef.current = resolve;
      setConfirmation(kind);
    });

  const settle = (confirmed: boolean) => {
    settleRef.current?.(confirmed);
    settleRef.current = null;
    setConfirmation(null);
  };

  const { handleSave, leaveDialogProps } = useEditorSave(
    form,
    async (values) => {
      const kind = needsConfirmation(settingsQuery.data.expiry, values.expiry);

      if (kind && !(await confirm(kind))) {
        return false;
      }

      try {
        const saved = await saveMutation.mutateAsync(buildExpirySettingsUpdate(values));
        form.reset(expirySettingsDefaults(saved), { keepDirtyValues: false });
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
        {!IS_PRO_BUILD && <ExpiryMoreInProCard />}

        <div className="flex items-center justify-end gap-2">
          <Button type="submit" disabled={!form.formState.isDirty || saveMutation.isPending}>
            {__('Save changes', 'yayloyalty')}
          </Button>
        </div>
      </form>

      <ConfirmDialog
        open={confirmation !== null}
        onOpenChange={(open) => !open && settle(false)}
        title={confirmation === 'batch' ? CONFIRM_BATCH_TITLE() : CONFIRM_SHORTEN_TITLE()}
        description={confirmation === 'batch' ? CONFIRM_BATCH_BODY() : CONFIRM_SHORTEN_BODY()}
        confirmLabel={CONFIRM_SAVE()}
        cancelLabel={CONFIRM_CANCEL()}
        onConfirm={() => settle(true)}
      />
      <UnsavedChangeDialog {...leaveDialogProps} />
    </FormProvider>
  );
}
