import { __ } from '@wordpress/i18n';

import { DEFAULT_CAMPAIGN_CONFIG, LiteCampaignTrigger } from '@/lib/schema/campaign.schema';
import { CampaignFormValues } from '../editor/campaign-editor.form';

/**
 * One card on the "Add a campaign" chooser. First-order bonus, Birthday
 * points and VIP multiplier are left out: the first two need Pro-only
 * triggers, and VIP multiplier needs a tier condition that
 * CampaignConditionSet rejects today. Welcome bonus and Review reward give
 * signup and review a starting point.
 */
export interface CampaignTemplate {
  /** The URL segment at campaigns/new/:templateId. */
  id: string;
  title: string;
  description: string;
  /** The card's footer line — a real configuration this seeds, per the wireframe's own callout 2, never an invented benefit. */
  example: string;
  trigger: LiteCampaignTrigger;
  /**
   * A function, not a static object: every call must return values this
   * template's own picker never shares with another visit, or another
   * template's seed — see synthesizeDraftCampaign's sibling concern in
   * campaign-editor.form.ts.
   */
  seed: () => CampaignFormValues;
}

/**
 * Every template opens as a draft — nothing here can start earning by
 * accident, matching the chooser's own note that a template is only a
 * starting point. No dates are seeded: the merchant picks them, per the
 * same note.
 *
 * A function, not a module-level const: matching campaign-labels.ts's own
 * functions (e.g. conditionTypeLabel()), so the bundler defers every __()
 * call to when this is actually invoked. A const array would still get
 * built at module-init time even in the Lite bundle — Rollup keeps a
 * module-level statement that calls an imported function it can't prove
 * pure, regardless of whether the binding it produces ends up unused —
 * shipping this Pro-only copy as readable text in Lite's JS.
 */
export function getCampaignTemplates(): CampaignTemplate[] {
  return [
    {
      id: 'scratch',
      title: __('Start from scratch', 'yayloyalty'),
      description: __('Choose the trigger and write every field yourself.', 'yayloyalty'),
      example: __('Purchase, signup or review · no conditions · priority 0', 'yayloyalty'),
      // The radio defaults to purchase; §2 in the builder is fully editable from here, unlike edit mode.
      trigger: 'purchase',
      seed: () => ({
        name: '',
        status: 'draft',
        priority: 0,
        config: structuredClone(DEFAULT_CAMPAIGN_CONFIG.purchase),
        conditions: [],
        starts_at: null,
        ends_at: null,
      }),
    },
    {
      id: 'double-points',
      title: __('Double points weekend', 'yayloyalty'),
      description: __(
        'Multiply the default rate for a few days. Ends by itself, so nobody has to remember to switch it off.',
        'yayloyalty',
      ),
      example: __('Example: ×2 the default rate, priority 20', 'yayloyalty'),
      trigger: 'purchase',
      seed: () => ({
        name: __('Double points weekend', 'yayloyalty'),
        status: 'draft',
        priority: 20,
        config: { ...structuredClone(DEFAULT_CAMPAIGN_CONFIG.purchase), mode: 'multiplier', multiplier: 2 },
        conditions: [],
        starts_at: null,
        ends_at: null,
      }),
    },
    {
      id: 'category-boost',
      title: __('Category boost', 'yayloyalty'),
      description: __('A higher rate on one category, to move stock without discounting it.', 'yayloyalty'),
      example: __('Example: 3 points per unit on one category, priority 10', 'yayloyalty'),
      trigger: 'purchase',
      seed: () => ({
        name: __('Category boost', 'yayloyalty'),
        status: 'draft',
        priority: 10,
        config: { ...structuredClone(DEFAULT_CAMPAIGN_CONFIG.purchase), points_per_unit: 3 },
        // Deliberately empty: CampaignEditorConditions blocks saving until a category is picked,
        // which is the point — a boost with no category is not yet a boost.
        conditions: [{ type: 'category_in_cart', operator: 'in', value: [] }],
        starts_at: null,
        ends_at: null,
      }),
    },
    {
      id: 'welcome-bonus',
      title: __('Welcome bonus', 'yayloyalty'),
      description: __('A one-off reward the first time a customer creates an account.', 'yayloyalty'),
      example: __('Example: 100 points, once per customer', 'yayloyalty'),
      trigger: 'signup',
      seed: () => ({
        name: __('Welcome bonus', 'yayloyalty'),
        status: 'draft',
        priority: 0,
        config: structuredClone(DEFAULT_CAMPAIGN_CONFIG.signup),
        conditions: [],
        starts_at: null,
        ends_at: null,
      }),
    },
    {
      id: 'review-reward',
      title: __('Review reward', 'yayloyalty'),
      description: __('Points for a published product review.', 'yayloyalty'),
      example: __('Example: 50 points, verified purchases only', 'yayloyalty'),
      trigger: 'review',
      seed: () => ({
        name: __('Review reward', 'yayloyalty'),
        status: 'draft',
        priority: 0,
        config: structuredClone(DEFAULT_CAMPAIGN_CONFIG.review),
        conditions: [],
        starts_at: null,
        ends_at: null,
      }),
    },
  ];
}

export function findTemplate(id: string): CampaignTemplate | undefined {
  return getCampaignTemplates().find((template) => template.id === id);
}
