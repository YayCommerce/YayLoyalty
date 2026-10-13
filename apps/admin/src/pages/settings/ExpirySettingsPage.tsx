import { useRef, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { FormProvider, useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useSaveSettingsMutation, useSettingsQuery } from '@/lib/queries/settings.queries';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { ProGate } from '@/components/pro/ProGate';
import { BatchExpirySection, ExpiryModeSection } from '@/pro';
import {
  buildExpirySettingsUpdate,
  expirySettingsDefaults,
  expirySettingsFormSchema,
  ExpirySettingsFormValues,
  needsConfirmation,
} from './expiry-settings.form';
import { ExpiryInactivityCard } from './ExpiryInactivityCard';

type Confirmation = NonNullable<ReturnType<typeof needsConfirmation>>;

/** Title and body per confirmation. Functions, not constants: `__()` must run after the locale loads. */
const CONFIRM_COPY: Record<Confirmation, () => { title: string; description: string }> = {
  shorten: () => ({
    title: __('Shorten points expiry?', 'yayloyalty'),
    // 30 mirrors ExpiryPolicy::GRACE_DAYS.
    description: __(
      'Nobody loses points today. Customers whose new expiry date falls within 30 days get 30 days from today.',
      'yayloyalty',
    ),
  }),
  batch: () => ({
    title: __('Switch to batch expiry?', 'yayloyalty'),
    description: __("Each earning will expire on its own date. This can't be switched back.", 'yayloyalty'),
  }),
};

/** Lite's upsell below the inactivity card. */
function ExpiryMoreInProCard() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Points expire in batches', 'yayloyalty')}</CardTitle>
        <CardDescription>
          {__('Expire each earning a set time after it was earned. Available in YayLoyalty Pro.', 'yayloyalty')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ProGate />
      </CardContent>
    </Card>
  );
}

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
  const mode = useWatch({ control: form.control, name: 'expiry.mode' });
  const copy = confirmation ? CONFIRM_COPY[confirmation]() : { title: '', description: '' };

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
        {IS_PRO_BUILD && <ExpiryModeSection />}
        {IS_PRO_BUILD && mode === 'batch' ? <BatchExpirySection /> : <ExpiryInactivityCard />}
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
        title={copy.title}
        description={copy.description}
        confirmLabel={__('Save', 'yayloyalty')}
        cancelLabel={__('Cancel', 'yayloyalty')}
        onConfirm={() => settle(true)}
      />
      <UnsavedChangeDialog {...leaveDialogProps} />
    </FormProvider>
  );
}
