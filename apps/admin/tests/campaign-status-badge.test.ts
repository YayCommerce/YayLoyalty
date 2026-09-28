import { describe, expect, it } from 'vitest';

import { CAMPAIGN_DISPLAY_STATUSES, CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { campaignStatusBadgeVariant } from '@/pro/campaigns/campaign-labels';

/** One colour per status, so the table, editor and performance page agree. */
describe('campaignStatusBadgeVariant', () => {
  const expected: Record<CampaignDisplayStatus, string> = {
    active: 'success-soft',
    scheduled: 'primary-soft',
    paused: 'warning-soft',
    draft: 'outline',
    ended: 'dimmed',
    archived: 'dimmed',
  };

  it.each(CAMPAIGN_DISPLAY_STATUSES)('%s', (status) => {
    expect(campaignStatusBadgeVariant(status)).toBe(expected[status]);
  });
});
