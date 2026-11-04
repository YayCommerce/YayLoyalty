import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';

import { formatStoreNumber, formatWPDate } from '@/lib/helpers/format.helper';
import type { AssignCustomerTierInput } from '@/lib/schema/tier.schema';

/** Mirrors the `days` bounds of CustomerTierRestController's POST route, the source of truth. */
export const MANUAL_TIER_DAYS_MAX = 3650;

/** The Assign tier dialog's fields. `tier_id` is a string because the Select works in strings; '' is none chosen. */
export interface CustomerTierAssignValues {
  tier_id: string;
  end: 'none' | 'limited';
  days: number;
}

export const CUSTOMER_TIER_ASSIGN_DEFAULTS: CustomerTierAssignValues = { tier_id: '', end: 'none', days: 30 };

/** `days` is checked only for a limited floor; the server re-checks both fields. */
export function customerTierAssignSchema() {
  return z
    .object({
      tier_id: z.string().min(1, __('Choose a tier.', 'yayloyalty')),
      end: z.enum(['none', 'limited']),
      days: z.number(),
    })
    .superRefine((values, ctx) => {
      if (
        values.end === 'limited' &&
        (!Number.isInteger(values.days) || values.days < 1 || values.days > MANUAL_TIER_DAYS_MAX)
      ) {
        ctx.addIssue({
          code: 'custom',
          path: ['days'],
          message: sprintf(
            /* translators: %s: the most days a manual tier may last. */
            __('Use a whole number of days from 1 to %s.', 'yayloyalty'),
            formatStoreNumber(MANUAL_TIER_DAYS_MAX, 0),
          ),
        });
      }
    });
}

export function toAssignCustomerTierInput(values: CustomerTierAssignValues): AssignCustomerTierInput {
  return { tier_id: Number(values.tier_id), days: values.end === 'limited' ? values.days : null };
}

/** The date a floor of `days` days set now ends on, in the site's date format. */
export function manualTierEndsOn(days: number): string {
  return formatWPDate(new Date(Date.now() + days * 86_400_000));
}
