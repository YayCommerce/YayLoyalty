import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';

import { designLauncherPositionSchema } from '@/lib/schema/design.schema';
import { POINTS_LABEL_MAX_LENGTH } from '@/pages/settings/general-settings.form';

/** Mirrors SetupHelper, the source of truth for the draft's rules and the option's shape. */

/** SetupHelper::LAST_STEP. */
export const SETUP_LAST_STEP = 5;

const pointsLabelSchema = z
  .string()
  .trim()
  .min(1, { message: __('Enter a name.', 'yayloyalty') })
  .max(POINTS_LABEL_MAX_LENGTH, {
    /* translators: %d: the longest points name allowed, 30. */
    message: sprintf(__('Use %d characters or fewer.', 'yayloyalty'), POINTS_LABEL_MAX_LENGTH),
  });

/** Money on the wire: a decimal string above 0, as "1" or "2.50". */
const positiveAmountSchema = z
  .string()
  .regex(/^\d+(\.\d+)?$/, { message: __('Enter an amount, like 1 or 2.50.', 'yayloyalty') })
  .refine((value) => Number(value) > 0, { message: __('Enter an amount above 0.', 'yayloyalty') });

const wholeSchema = (min: number) =>
  z
    .number({ invalid_type_error: __('Enter a whole number.', 'yayloyalty') })
    .int({ message: __('Enter a whole number.', 'yayloyalty') })
    .min(min, {
      /* translators: %d: the smallest number allowed. */
      message: sprintf(__('Enter %d or more.', 'yayloyalty'), min),
    });

const flatCampaignSchema = z.object({ enabled: z.boolean(), points: wholeSchema(0) });

export const setupDraftSchema = z.object({
  points_label_singular: pointsLabelSchema,
  points_label_plural: pointsLabelSchema,
  earn: z.object({ points: wholeSchema(1), amount: positiveAmountSchema }),
  signup: flatCampaignSchema,
  review: flatCampaignSchema,
  reward: z.object({
    points: wholeSchema(1),
    amount: positiveAmountSchema,
    min_points: wholeSchema(0),
    max_percent: wholeSchema(1).max(100, { message: __('Enter 100 or less.', 'yayloyalty') }),
  }),
  launcher: z.object({
    position: designLauncherPositionSchema,
    brand_color: z.string().regex(/^#[0-9a-fA-F]{6}$/, { message: __('Enter a colour like #1F6FB2.', 'yayloyalty') }),
  }),
});

export type SetupDraft = z.infer<typeof setupDraftSchema>;

/** Mirrors SetupRestController::live(): the live values that pre-fill a missing draft. */
export const setupLiveSchema = z.object({
  points_label_singular: z.string(),
  points_label_plural: z.string(),
  /** Points per 1 currency unit, as the purchase campaign stores it. */
  purchase_rate: z.number(),
  signup: z.object({ enabled: z.boolean(), points: z.number().int() }),
  review: z.object({ enabled: z.boolean(), points: z.number().int() }),
  reward: z.object({
    points_per_currency_unit: z.number(),
    min_points: z.number().int(),
    /** null is no cap, shown as 100%. */
    max_percent_of_subtotal: z.number().nullable(),
  }),
  launcher: z.object({ position: designLauncherPositionSchema, brand_color: z.string() }),
});

export type SetupLive = z.infer<typeof setupLiveSchema>;

export const setupStatusSchema = z.enum(['pending', 'completed']);

/** GET /setup. A stored draft is not re-validated here: SetupHelper already dropped an invalid one. */
export const setupStateSchema = z.object({
  status: setupStatusSchema,
  step: z.number().int().min(1).max(SETUP_LAST_STEP),
  draft: z.unknown().nullable(),
  live: setupLiveSchema,
});

export type SetupState = Omit<z.infer<typeof setupStateSchema>, 'draft'> & { draft: SetupDraft | null };

/** window.yayLoyaltyAdmin.setup, from SetupHelper::localized_state(). */
export type SetupLocalized = {
  status: z.infer<typeof setupStatusSchema>;
  step: number;
  first_run_dismissed: boolean;
};

/** POST /setup/draft: what SetupHelper::save_draft() returns. */
export const setupSavedStateSchema = z.object({
  status: setupStatusSchema,
  step: z.number().int().min(1).max(SETUP_LAST_STEP),
  draft: setupDraftSchema.nullable(),
  first_run_dismissed: z.boolean(),
});

export const setupCompleteResponseSchema = z.object({ status: z.literal('completed') });

export const setupDismissResponseSchema = z.object({ first_run_dismissed: z.literal(true) });
