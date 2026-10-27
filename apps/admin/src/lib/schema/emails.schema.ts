import { z } from 'zod';

import { settingsSchema } from '@/lib/schema/settings.schema';

/** Mirrors EmailsHelper::get_emails(), the source of truth. */

/**
 * RHF resolver input; what POST /emails takes. Keyed by a bare string, not a closed enum: the ids
 * are whatever the server registered with WooCommerce.
 */
export const emailsFormSchema = z.object({
  emails: z.record(z.string(), z.boolean()),
  throttle_per_week: settingsSchema.shape.emails.shape.throttle_per_week,
});
export type EmailsFormValues = z.infer<typeof emailsFormSchema>;

/**
 * One registered loyalty email, as EmailsHelper::to_row() builds it. Plain types rather than Zod:
 * server-authored, nothing to validate. `edit_url` opens the email's own WooCommerce settings screen.
 * A `manual` email is always on: it has no switch and is never posted.
 */
export type EmailItem = {
  id: string;
  title: string;
  description: string;
  recipient: 'customer' | 'admin';
  enabled: boolean;
  manual: boolean;
  edit_url: string;
};
export type EmailGroup = { group: string; group_label: string; items: EmailItem[] };
export type EmailsSending = { from_name: string; from_address: string; wc_emails_url: string };

export type EmailsResponse = {
  groups: EmailGroup[];
  sending: EmailsSending;
  throttle_per_week: number | null;
};

/** Only the toggles + throttle cap are form state; titles, links and sending info are never posted back. */
export function toEmailsFormValues(data: EmailsResponse): EmailsFormValues {
  const emails: Record<string, boolean> = {};

  for (const group of data.groups) {
    for (const item of group.items) {
      if (item.manual) continue;
      emails[item.id] = item.enabled;
    }
  }

  return { emails, throttle_per_week: data.throttle_per_week };
}
