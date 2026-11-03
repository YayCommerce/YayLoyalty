import { __ } from '@wordpress/i18n';

import { BirthdayConfig, CreatableCampaignTrigger, DEFAULT_CAMPAIGN_CONFIG } from '@/lib/schema/campaign.schema';
import { CampaignFormValues } from '../editor/campaign-editor.form';

/** One card on the "Add a campaign" chooser: one per creatable trigger, each opening an empty campaign of that kind. */
export interface CampaignTemplate {
  /** The URL segment at campaigns/new/:trigger; the trigger itself. */
  id: CreatableCampaignTrigger;
  title: string;
  description: string;
  /** The card's footer line: something this kind can be set up to do, never an invented benefit. */
  example: string;
  trigger: CreatableCampaignTrigger;
  /** A function, not a static object: each call returns fresh values that no other visit or template shares. */
  seed: () => CampaignFormValues;
}

/**
 * Every kind opens Active with a starter name and no dates; "Save as draft" saves it without going live.
 *
 * A function, not a module-level const: Rollup keeps a module-level call to __() even when its
 * result is unused, which would ship this Pro-only copy in the Lite bundle.
 */
export function getCampaignTemplates(): CampaignTemplate[] {
  return [
    {
      id: 'purchase',
      title: __('Order placed', 'yayloyalty'),
      description: __('Earn points on completed orders, at a rate per amount spent.', 'yayloyalty'),
      example: __('e.g. double points on one category for a weekend', 'yayloyalty'),
      trigger: 'purchase',
      seed: () => emptySeed('purchase', __('Points for orders', 'yayloyalty')),
    },
    {
      id: 'signup',
      title: __('Account created', 'yayloyalty'),
      description: __('A one-off bonus the first time a customer creates an account.', 'yayloyalty'),
      example: __('e.g. a 100-point welcome bonus', 'yayloyalty'),
      trigger: 'signup',
      seed: () => emptySeed('signup', __('Signup bonus', 'yayloyalty')),
    },
    {
      id: 'review',
      title: __('Review published', 'yayloyalty'),
      description: __('Points for each approved product review.', 'yayloyalty'),
      example: __('e.g. 50 points for verified buyers', 'yayloyalty'),
      trigger: 'review',
      seed: () => emptySeed('review', __('Review bonus', 'yayloyalty')),
    },
    {
      id: 'birthday',
      title: __('Birthday', 'yayloyalty'),
      description: __("Bonus points once a year on each customer's birthday.", 'yayloyalty'),
      example: __('e.g. 200 points, a week early', 'yayloyalty'),
      trigger: 'birthday',
      seed: () => emptySeed('birthday', __('Birthday bonus', 'yayloyalty')),
    },
  ];
}

/** Config a new campaign starts from. Birthday has no built-in campaign, so no Campaign::default_config() entry. */
const SEED_CONFIG: Record<CreatableCampaignTrigger, Record<string, unknown>> = {
  ...DEFAULT_CAMPAIGN_CONFIG,
  birthday: { points: 100, days_before: 0 } satisfies BirthdayConfig,
};

function emptySeed(trigger: CreatableCampaignTrigger, name: string): CampaignFormValues {
  return {
    name,
    status: 'active',
    config: structuredClone(SEED_CONFIG[trigger]),
    conditions: [],
    starts_at: null,
    ends_at: null,
  };
}

export function findTemplate(id: string): CampaignTemplate | undefined {
  return getCampaignTemplates().find((template) => template.id === id);
}
