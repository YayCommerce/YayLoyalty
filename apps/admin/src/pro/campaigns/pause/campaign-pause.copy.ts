import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import type { Campaign, CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { givesLabel } from '../campaign-labels';

/**
 * Copy for CampaignPauseDialog. Per-trigger sentences are written out whole,
 * same reason as campaign-performance.copy.ts.
 */

/** The dialog's one sentence: what stops, and that it can be resumed. */
export function pauseBody(campaign: Campaign, displayStatus: CampaignDisplayStatus): string {
  if (displayStatus === 'scheduled') {
    return sprintf(
      /* translators: %s: the date the campaign was due to start. */
      __("It won't start on %s. You can resume it anytime.", 'yayloyalty'),
      parseWPDate(campaign.starts_at ?? undefined),
    );
  }

  const gives = givesLabel(campaign.trigger, campaign.config);

  switch (campaign.trigger) {
    case 'purchase':
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "×2 points". */
        __('Orders stop earning %s from this campaign. You can resume it anytime.', 'yayloyalty'),
        gives,
      );
    case 'signup':
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "200 points". */
        __('New signups stop earning %s from this campaign. You can resume it anytime.', 'yayloyalty'),
        gives,
      );
    case 'review':
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "50 points". */
        __('Reviews stop earning %s from this campaign. You can resume it anytime.', 'yayloyalty'),
        gives,
      );
    default:
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "500 points". */
        __('Customers stop earning %s from this campaign. You can resume it anytime.', 'yayloyalty'),
        gives,
      );
  }
}
