import { zodResolver } from '@hookform/resolvers/zod';
import { FormProvider, useForm } from 'react-hook-form';
import { Link } from 'react-router';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useEmailsQuery, useSaveEmailsMutation } from '@/lib/queries/emails.queries';
import { emailsFormSchema, EmailsFormValues, EmailsResponse, toEmailsFormValues } from '@/lib/schema/emails.schema';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { EmailsMoreInProCard } from './EmailsMoreInProCard';
import { EmailsSettingsSkeleton } from './EmailsSettingsSkeleton';
import { EmailsTable } from './EmailsTable';
import { EmailsThrottleCard } from './EmailsThrottleCard';

/**
 * Loads the registered emails, then mounts the form, so the form's hooks always have data to seed from.
 * Data is checked before the error: a failed background refetch keeps the old data, and must not
 * unmount a form with unsaved edits.
 */
export default function EmailsSettingsPage() {
  const emailsQuery = useEmailsQuery();

  if (emailsQuery.data) {
    return <EmailsSettingsForm data={emailsQuery.data} />;
  }

  if (emailsQuery.isError) {
    return (
      <Card>
        <CardContent className="flex items-center justify-between gap-3 text-sm">
          <span className="text-muted-foreground">{__('Email settings could not be loaded.', 'yayloyalty')}</span>
          <Button type="button" variant="outline" size="sm" onClick={() => void emailsQuery.refetch()}>
            {__('Retry', 'yayloyalty')}
          </Button>
        </CardContent>
      </Card>
    );
  }

  return <EmailsSettingsSkeleton />;
}

function EmailsSettingsForm({ data }: { data: EmailsResponse }) {
  const saveMutation = useSaveEmailsMutation();

  // `values` (not defaultValues) + keepDirtyValues: a refetch re-seeds the form without
  // clobbering a field mid-edit, same reasoning as DesignSettingsPage.
  const form = useForm<EmailsFormValues>({
    resolver: zodResolver(emailsFormSchema),
    values: toEmailsFormValues(data),
    resetOptions: { keepDirtyValues: true },
  });

  const { showDialog, confirmLeave, cancelLeave } = useRouteLeaveGuard(form.formState.isDirty);

  const handleSave = form.handleSubmit(async (values) => {
    try {
      const saved = await saveMutation.mutateAsync(values);
      form.reset(toEmailsFormValues(saved));
      confirmLeave();
      toast.success(__('Email settings saved.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Failed to save email settings.', 'yayloyalty'));
    }
  });

  const { sending } = data;

  return (
    <>
      <FormProvider {...form}>
        <form onSubmit={handleSave} className="flex flex-col gap-4">
          <EmailsTable groups={data.groups} />

          {IS_PRO_BUILD ? null : <EmailsMoreInProCard />}

          <EmailsThrottleCard />

          <Card>
            <CardHeader>
              <CardTitle>{__('Sending', 'yayloyalty')}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2 text-sm">
              <div>
                <span className="text-muted-foreground">{__('From name', 'yayloyalty')}: </span>
                {sending.from_name || '—'}
              </div>
              <div>
                <span className="text-muted-foreground">{__('From address', 'yayloyalty')}: </span>
                {sending.from_address || '—'}
              </div>
              <div className="flex flex-wrap gap-x-4">
                <a href={sending.wc_emails_url} target="_blank" rel="noreferrer" className="text-primary underline">
                  {__('Manage in WooCommerce', 'yayloyalty')}
                </a>
                <Link to="/settings/general" className="text-primary underline">
                  {__('Expiry reminder timing', 'yayloyalty')}
                </Link>
              </div>
            </CardContent>
          </Card>

          <div className="flex items-center justify-end gap-2">
            <Button type="submit" disabled={!form.formState.isDirty || saveMutation.isPending}>
              {__('Save changes', 'yayloyalty')}
            </Button>
          </div>
        </form>
      </FormProvider>

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
