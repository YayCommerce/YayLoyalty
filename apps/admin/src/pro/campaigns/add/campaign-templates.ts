import { __ } from '@wordpress/i18n';

import { DEFAULT_CAMPAIGN_CONFIG, LiteCampaignTrigger } from '@/lib/schema/campaign.schema';
import { CampaignFormValues } from '../editor/campaign-editor.form';

/** One card on the "Add a campaign" chooser: one per Lite trigger, each opening an empty campaign of that kind. */
export interface CampaignTemplate {
  /** The URL segment at campaigns/new/:templateId; the trigger itself. */
  id: LiteCampaignTrigger;
  title: string;
  description: string;
  /** The card's footer line: something this kind can be set up to do, never an invented benefit. */
  example: string;
  trigger: LiteCampaignTrigger;
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
  ];
}

function emptySeed(trigger: LiteCampaignTrigger, name: string): CampaignFormValues {
  return {
    name,
    status: 'active',
    config: structuredClone(DEFAULT_CAMPAIGN_CONFIG[trigger]),
    conditions: [],
    starts_at: null,
    ends_at: null,
  };
}

export function findTemplate(id: string): CampaignTemplate | undefined {
  return getCampaignTemplates().find((template) => template.id === id);
}
