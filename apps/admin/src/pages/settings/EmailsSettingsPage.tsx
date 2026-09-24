import { zodResolver } from '@hookform/resolvers/zod';
import { HTTPError } from 'ky';
import { FormProvider, useForm } from 'react-hook-form';
import { Link } from 'react-router';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { getApiErrorMessage } from '@/lib/api/api';
import { useEmailsQuery, useSaveEmailsMutation } from '@/lib/queries/emails.queries';
import { emailsFormSchema, EmailsFormValues, EmailsResponse } from '@/lib/schema/emails.schema';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { EmailsTable } from './EmailsTable';
import { EmailsThrottleCard } from './EmailsThrottleCard';

/** Only the toggles + throttle cap are form state; labels/descriptions/recipient/sending come from query data and are never posted back. */
function toFormValues(data: EmailsResponse): EmailsFormValues {
  const emails: Record<string, boolean> = {};

  for (const group of data.groups) {
    for (const item of group.items) {
      emails[item.id] = item.enabled;
    }
  }

  return { emails, throttlePerWeek: data.throttlePerWeek };
}

export default function EmailsSettingsPage() {
  const emailsQuery = useEmailsQuery();
  const saveMutation = useSaveEmailsMutation();

  // `values` (not defaultValues) + keepDirtyValues: a refetch re-seeds the form without
  // clobbering a field mid-edit, same reasoning as DesignSettingsPage.
  const form = useForm<EmailsFormValues>({
    resolver: zodResolver(emailsFormSchema),
    values: toFormValues(emailsQuery.data),
    resetOptions: { keepDirtyValues: true },
  });

  const { showDialog, confirmLeave, cancelLeave } = useRouteLeaveGuard(form.formState.isDirty);

  const handleSave = form.handleSubmit(async (values) => {
    try {
      const saved = await saveMutation.mutateAsync(values);
      form.reset(toFormValues(saved));
      confirmLeave();
      toast.success(__('Email settings saved.', 'yayloyalty'));
    } catch (error) {
      if (!(error instanceof HTTPError && 403 === error.response.status)) {
        toast.error(await getApiErrorMessage(error, __('Failed to save email settings.', 'yayloyalty')));
      }
    }
  });

  const { sending } = emailsQuery.data;

  return (
    <>
      <FormProvider {...form}>
        <form onSubmit={handleSave} className="flex flex-col gap-4">
          <EmailsTable groups={emailsQuery.data.groups} wcEmailsUrl={sending.wc_emails_url} />

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
