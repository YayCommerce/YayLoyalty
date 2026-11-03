import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';

import { formatStoreNumber } from '@/lib/helpers/format.helper';
import { Tier, TIER_LIMITS, TierInput } from '@/lib/schema/tier.schema';

/** The tier dialog's fields. The multiplier is a number while editing and a two-decimal string on the wire. */
export interface TierFormValues {
  name: string;
  threshold: number;
  multiplier: number;
  entry_bonus: number;
  color: string;
}

/** Whole hundredths that are a multiple of the 0.05 step; tolerant of float noise such as 1.1500000001. */
function isMultiplierStep(value: number): boolean {
  const hundredths = Math.round(value * 100);

  return Math.abs(value * 100 - hundredths) < 1e-6 && hundredths % Math.round(TIER_LIMITS.multiplierStep * 100) === 0;
}

/** Inline messages only. The server re-checks every rule, uniqueness included, and its message is toasted. */
export function tierFormSchema() {
  return z.object({
    name: z
      .string()
      .trim()
      .min(1, __('Enter a name.', 'yayloyalty'))
      .max(
        TIER_LIMITS.nameMax,
        /* translators: %s: the longest tier name, in characters. */
        sprintf(__('Use %s characters or fewer.', 'yayloyalty'), formatStoreNumber(TIER_LIMITS.nameMax, 0)),
      ),
    threshold: z
      .number()
      .int()
      .min(0)
      .max(
        TIER_LIMITS.thresholdMax,
        /* translators: %s: the highest number of points a tier may start at. */
        sprintf(__('Use %s points or fewer.', 'yayloyalty'), formatStoreNumber(TIER_LIMITS.thresholdMax, 0)),
      ),
    multiplier: z
      .number()
      .refine(
        (value) => value >= TIER_LIMITS.multiplierMin && value <= TIER_LIMITS.multiplierMax && isMultiplierStep(value),
        {
          message: sprintf(
            /* translators: 1: the lowest multiplier, 2: the highest multiplier. */
            __('Use a value from %1$s to %2$s, in steps of 0.05.', 'yayloyalty'),
            formatStoreNumber(TIER_LIMITS.multiplierMin, 2),
            formatStoreNumber(TIER_LIMITS.multiplierMax, 2),
          ),
        },
      ),
    entry_bonus: z
      .number()
      .int()
      .min(0)
      .max(
        TIER_LIMITS.entryBonusMax,
        /* translators: %s: the largest entry bonus, in points. */
        sprintf(__('Use %s points or fewer.', 'yayloyalty'), formatStoreNumber(TIER_LIMITS.entryBonusMax, 0)),
      ),
    color: z.string().regex(/^#[0-9A-Fa-f]{6}$/, __('Pick a colour.', 'yayloyalty')),
  });
}

/** Values for a new tier: placed 1,000 points above the highest live one. */
export function newTierValues(tiers: Tier[]): TierFormValues {
  const highest = tiers.reduce((max, tier) => Math.max(max, tier.threshold), -1000);

  return {
    name: '',
    threshold: Math.min(highest + 1000, TIER_LIMITS.thresholdMax),
    multiplier: 1,
    entry_bonus: 0,
    color: '#6B7280',
  };
}

export function tierFormValues(tier: Tier): TierFormValues {
  return {
    name: tier.name,
    threshold: tier.threshold,
    multiplier: Number(tier.multiplier),
    entry_bonus: tier.entry_bonus,
    color: tier.color,
  };
}

export function toTierInput(values: TierFormValues): TierInput {
  return {
    name: values.name.trim(),
    threshold: values.threshold,
    multiplier: values.multiplier.toFixed(2),
    entry_bonus: values.entry_bonus,
    color: values.color.toUpperCase(),
  };
}
