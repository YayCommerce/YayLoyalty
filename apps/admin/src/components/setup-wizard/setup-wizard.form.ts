import type { UseFormReturn } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { rateFromSpend } from '@/lib/helpers/points.helper';
import type { Setup } from '@/lib/schema/setup.schema';

export type WizardStep = 1 | 2 | 3 | 4 | 5;

export const WIZARD_STEP_COUNT = 5;

/** The made-up balance the previews show; the store has no customers yet. */
export const SAMPLE_BALANCE = 850;

/**
 * The wizard's values in the shape its fields edit. Rates are stored per 1 currency unit,
 * but the merchant types them as pairs ("3 points for every 2.00", "100 points = 1.00").
 */
export type SetupForm = {
  pointsLabelSingular: string;
  pointsLabelPlural: string;
  earnPoints: number;
  earnSpend: number;
  signupEnabled: boolean;
  signupPoints: number;
  reviewEnabled: boolean;
  reviewPoints: number;
  worthPoints: number;
  worthAmount: number;
  minPoints: number;
  /** 1–100; 100 is stored as null, no limit. */
  maxPercent: number;
  brandColor: string;
};

/** What every step component receives. */
export type WizardStepProps = { form: UseFormReturn<SetupForm> };

/**
 * The first-run values, copied from the PHP seeds: Campaign::default_config(), Reward::default_config(),
 * SettingsHelper's labels and DesignSchema's brand_color.
 */
export function setupDefaults(): SetupForm {
  return {
    pointsLabelSingular: __('Point', 'yayloyalty'),
    pointsLabelPlural: __('Points', 'yayloyalty'),
    earnPoints: 1,
    earnSpend: 1,
    signupEnabled: true,
    signupPoints: 100,
    reviewEnabled: true,
    reviewPoints: 50,
    worthPoints: 100,
    worthAmount: 1,
    minPoints: 100,
    maxPercent: 100,
    brandColor: '#1f6fb2',
  };
}

export function toSetupPayload(form: SetupForm): Setup {
  return {
    points_label_singular: form.pointsLabelSingular,
    points_label_plural: form.pointsLabelPlural,
    // A spend of 0 has no rate; 0 makes the server refuse it rather than store a guess.
    purchase: { points_per_unit: rateFromSpend(form.earnSpend, form.earnPoints) ?? 0 },
    signup: { enabled: form.signupEnabled, points: form.signupPoints },
    review: { enabled: form.reviewEnabled, points: form.reviewPoints },
    cart_discount: {
      points_per_currency_unit: rateFromSpend(form.worthAmount, form.worthPoints) ?? 0,
      min_points: form.minPoints,
      max_percent_of_subtotal: form.maxPercent >= 100 ? null : form.maxPercent,
    },
    brand_color: form.brandColor,
  };
}

/** Points a customer earns on an order of `amount`, as the purchase campaign floors them. */
export function pointsForSpend(form: Pick<SetupForm, 'earnPoints' | 'earnSpend'>, amount: number): number {
  return form.earnSpend > 0 ? Math.floor((amount * form.earnPoints) / form.earnSpend) : 0;
}

/** Money `points` are worth at the wizard's redemption rate. */
export function valueOfPoints(form: Pick<SetupForm, 'worthPoints' | 'worthAmount'>, points: number): number {
  return form.worthPoints > 0 ? (points * form.worthAmount) / form.worthPoints : 0;
}
