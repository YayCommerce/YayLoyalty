import { z } from 'zod';

/** Mirrors EmailsMetadata::FREE_EMAIL_IDS — the ids the Lite build's UI renders editable. */
export const FREE_EMAIL_IDS = ['points_earned', 'points_expiring_soon', 'points_expired'] as const;

/**
 * RHF resolver input; what POST /emails takes. Keyed by a bare string, not a closed enum: the
 * backend now persists all 12 ids (Free and Pro share one store, see EmailsHelper's docblock), and
 * a closed enum here would throw the moment a Pro-only id renders in Lite.
 */
export const emailsFormSchema = z.object({
  emails: z.record(z.string(), z.boolean()),
  // Not enforced yet (no trigger/cron sends emails this release) — saved for that future task.
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
