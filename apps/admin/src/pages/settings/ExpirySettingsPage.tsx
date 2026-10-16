import { useRef, useState } from 'react';
import { valibotResolver } from '@hookform/resolvers/valibot';
import { FormProvider, useForm, useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useSaveSettingsMutation, useSettingsQuery } from '@/lib/queries/settings.queries';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { toast } from '@/components/ui/toast';
import { ProGate } from '@/components/pro/ProGate';
import { BatchExpirySection, ExpiryModeSection } from '@/pro';
import {
  buildExpirySettingsUpdate,
  expirySettingsDefaults,
  expirySettingsFormSchema,
  ExpirySettingsFormValues,
  graceFloorText,
  needsConfirmation,
} from './expiry-settings.form';
import { ExpiryInactivityCard } from './ExpiryInactivityCard';
import { ExpiryReminderRow } from './ExpiryReminderRow';
import { ExpiryShortenChoice } from './ExpiryShortenChoice';

type Confirmation = NonNullable<ReturnType<typeof needsConfirmation>>;

/** Title and body per confirmation. Functions, not constants: `__()` must run after the locale loads. */
const CONFIRM_COPY: Record<Confirmation, () => { title: string; description: string }> = {
  enable: () => ({
    title: __('Turn on points expiry?', 'yayloyalty'),
    description: sprintf(
      /* translators: %s: a date and how many days from today it is */
      __('Nobody loses points today. No expiry date is set before %s.', 'yayloyalty'),
      graceFloorText(),
    ),
  }),
  shorten: () => ({
    title: __('Shorten points expiry?', 'yayloyalty'),
    description: __('Choose what happens to points customers already hold.', 'yayloyalty'),
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
  const [onShorten, setOnShorten] = useState<ExpirySettingsFormValues['expiry']['on_shorten']>('keep');
  // The pending save awaits the dialog, so it reads the latest choice through a ref, not its stale closure.
  const onShortenRef = useRef(onShorten);
  const chooseOnShorten = (value: typeof onShorten) => {
    onShortenRef.current = value;
    setOnShorten(value);
  };
  // Settles the pending save: true when confirmed, false when cancelled.
  const settleRef = useRef<((confirmed: boolean) => void) | null>(null);

  const form = useForm<ExpirySettingsFormValues>({
    resolver: valibotResolver(expirySettingsFormSchema),
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

      if (kind === 'shorten') {
        chooseOnShorten(settingsQuery.data.expiry.on_shorten);
      }

      if (kind && !(await confirm(kind))) {
        return false;
      }

      // The shorten choice is made in the dialog, so it overrides the form's value.
      const next =
        kind === 'shorten' ? { ...values, expiry: { ...values.expiry, on_shorten: onShortenRef.current } } : values;

      try {
        const saved = await saveMutation.mutateAsync(buildExpirySettingsUpdate(next));
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
        <ExpiryReminderRow />
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
      >
        {confirmation === 'shorten' && <ExpiryShortenChoice value={onShorten} onChange={chooseOnShorten} />}
      </ConfirmDialog>
      <UnsavedChangeDialog {...leaveDialogProps} />
    </FormProvider>
  );
}
