import { __, _n, sprintf } from '@wordpress/i18n';

import type { Campaign, DisplayStatus } from '@/lib/schema/campaign.schema';
import type { CampaignArchiveCase } from './campaign-archive.copy';

/** Copy for CampaignBulkArchiveDialog. */

/** One selected campaign, placed by resolveArchiveCase() exactly as its own dialog would place it. */
export interface BulkArchiveRow {
  campaign: Campaign;
  displayStatus: DisplayStatus;
  archiveCase: CampaignArchiveCase;
}

/**
 * The bulk dialog's body, one sentence per line. The last line carries <link> for
 * createInterpolateElement, pointing at the Archived view.
 */
export function bulkBodyLines(liveCount: number): string[] {
  const lines: string[] = [];

  if (liveCount > 0) {
    lines.push(
      sprintf(
        /* translators: %d: number of selected campaigns that are live right now. */
        _n(
          '%d of them is live and stops earning immediately.',
          '%d of them are live and stop earning immediately.',
          liveCount,
          'yayloyalty',
        ),
        liveCount,
      ),
    );
  }

  lines.push(
    __(
      'Points already awarded and customer history are kept. You can restore them from <link>Archived</link>.',
      'yayloyalty',
    ),
  );

  return lines;
}
