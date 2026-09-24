import { z } from 'zod';

import { settingsSchema } from '@/lib/schema/settings.schema';

/**
 * RHF resolver input; what POST /emails takes. Keyed by a bare string, not a closed enum: the ids
 * are whatever the server registered with WooCommerce (3 in Lite, 12 in Pro).
 */
export const emailsFormSchema = z.object({
  emails: z.record(z.string(), z.boolean()),
  /** The emails.throttle_per_week plugin setting, under its wire name. */
  throttlePerWeek: settingsSchema.shape.emails.shape.throttle_per_week,
});
export type EmailsFormValues = z.infer<typeof emailsFormSchema>;

/**
 * One registered loyalty email, as EmailsHelper::to_item() builds it. Plain types rather than Zod:
 * server-authored, nothing to validate. `editUrl` opens the email's own WooCommerce settings screen.
 */
export type EmailItem = {
  id: string;
  title: string;
  description: string;
  recipient: 'customer' | 'admin';
  enabled: boolean;
  editUrl: string;
};
export type EmailGroup = { group: string; groupLabel: string; items: EmailItem[] };
export type EmailsSending = { from_name: string; from_address: string; wc_emails_url: string };

export type EmailsResponse = {
  groups: EmailGroup[];
  sending: EmailsSending;
  throttlePerWeek: number;
};

/** Only the toggles + throttle cap are form state; titles, links and sending info are never posted back. */
export function toEmailsFormValues(data: EmailsResponse): EmailsFormValues {
  const emails: Record<string, boolean> = {};

  for (const group of data.groups) {
    for (const item of group.items) {
      emails[item.id] = item.enabled;
    }
  }

  return { emails, throttlePerWeek: data.throttlePerWeek };
}
