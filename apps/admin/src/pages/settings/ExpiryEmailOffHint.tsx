import { useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { EMAILS_QUERIES, useSaveEmailsMutation } from '@/lib/queries/emails.queries';
import { useSettingsQuery } from '@/lib/queries/settings.queries';
import { Button } from '@/components/ui/button';

interface ExpiryEmailOffHintProps {
  emailId: string;
  /** e.g. "The “Points expired” email is off." */
  message: string;
  successMessage: string;
}

/**
 * One line under an expiry setting saying its email is off, with a button that turns it on. Only once
 * expiry is saved on: before that the expiry emails are not registered.
 */
export function ExpiryEmailOffHint({ emailId, message, successMessage }: ExpiryEmailOffHintProps) {
  const isExpirySaved = useSettingsQuery().data.expiry.enabled;
  const emailsQuery = useQuery({ ...EMAILS_QUERIES.main, enabled: isExpirySaved });
  const saveMutation = useSaveEmailsMutation();

  const email = emailsQuery.data?.groups.flatMap((group) => group.items).find((item) => item.id === emailId);

  if (!isExpirySaved || !email || email.enabled) {
    return null;
  }

  const turnOn = async () => {
    try {
      await saveMutation.mutateAsync({ emails: { [email.id]: true } });
      toast.success(successMessage);
    } catch (error) {
      await toastApiError(error, __('Failed to turn on the email.', 'yayloyalty'));
    }
  };

  return (
    <p className="text-muted-foreground flex flex-wrap items-center gap-1 text-xs">
      {message}
      <Button
        variant="link"
        size="sm"
        className="text-primary h-auto p-0 text-xs underline"
        disabled={saveMutation.isPending}
        onClick={turnOn}
      >
        {__('Turn on email', 'yayloyalty')}
      </Button>
    </p>
  );
}
