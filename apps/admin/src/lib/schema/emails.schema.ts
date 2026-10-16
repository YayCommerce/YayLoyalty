import * as v from 'valibot';

import { settingsSchema } from '@/lib/schema/settings.schema';

/** Mirrors EmailsHelper::get_emails(), the source of truth. */

/**
 * RHF resolver input; what POST /emails takes. Keyed by a bare string, not a closed enum: the ids
 * are whatever the server registered with WooCommerce.
 */
export const emailsFormSchema = v.object({
  emails: v.record(v.string(), v.boolean()),
  throttle_per_week: settingsSchema.entries.emails.entries.throttle_per_week,
});
export type EmailsFormValues = v.InferOutput<typeof emailsFormSchema>;

/**
 * One registered loyalty email, as EmailsHelper::to_row() builds it. Plain types rather than a schema:
 * server-authored, nothing to validate. `edit_url` opens the email's own WooCommerce settings screen.
 */
export type EmailItem = {
  id: string;
  title: string;
  description: string;
  recipient: 'customer' | 'admin';
  enabled: boolean;
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
      emails[item.id] = item.enabled;
    }
  }

  return { emails, throttle_per_week: data.throttle_per_week };
}
