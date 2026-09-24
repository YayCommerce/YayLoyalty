import { z } from 'zod';

/**
 * RHF resolver input; what POST /emails takes. Keyed by a bare string, not a closed enum: the
 * backend persists every id (Free and Pro share one store, see EmailsHelper's docblock), and
 * a closed enum here would throw the moment a Pro-only id renders in Lite.
 */
export const emailsFormSchema = z.object({
  emails: z.record(z.string(), z.boolean()),
  throttlePerWeek: z.number().int().min(0),
});
export type EmailsFormValues = z.infer<typeof emailsFormSchema>;

/**
 * Server-authored static data (labels/descriptions), plain types rather than Zod — nothing to
 * validate. `isFree` is server-computed from EmailsMetadata::FREE_EMAIL_IDS so the FE never
 * duplicates that list; final editability is `item.isFree || IS_PRO_BUILD`.
 */
export type EmailItem = {
  id: string;
  label: string;
  description: string;
  recipient: string;
  enabled: boolean;
  isFree: boolean;
};
export type EmailGroup = { group: string; groupLabel: string; items: EmailItem[] };
export type EmailsSending = { from_name: string; from_address: string; wc_emails_url: string };

export type EmailsResponse = {
  groups: EmailGroup[];
  sending: EmailsSending;
  throttlePerWeek: number;
};

/** Only the toggles + throttle cap are form state; labels/descriptions/recipient/sending are never posted back. */
export function toEmailsFormValues(data: EmailsResponse): EmailsFormValues {
  const emails: Record<string, boolean> = {};

  for (const group of data.groups) {
    for (const item of group.items) {
      emails[item.id] = item.enabled;
    }
  }

  return { emails, throttlePerWeek: data.throttlePerWeek };
}
