import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import type { Campaign, CampaignDisplayStatus } from '@/lib/schema/campaign.schema';

/** Case resolution and copy for CampaignArchiveDialog. */

/** Which archive dialog a campaign gets. Points decide first, then status. */
export type CampaignArchiveCase = 'delete' | 'finished' | 'live';

/**
 * `isDeletable` is the server's own delete_item() guard (not built-in, zero ledger rows). Never
 * infer it from a points total: a fully refunded campaign nets 0 points but still has ledger rows,
 * and a permanent delete is the one mistake this dialog cannot undo.
 */
export function resolveArchiveCase(displayStatus: CampaignDisplayStatus, isDeletable: boolean): CampaignArchiveCase {
  if (isDeletable) {
    return 'delete';
  }

  return displayStatus === 'ended' ? 'finished' : 'live';
}

/**
 * The archive dialog's body, one sentence per line. The last line carries <link> for
 * createInterpolateElement, pointing at the Archived view.
 */
export function archiveBodyLines(campaign: Campaign, displayStatus: CampaignDisplayStatus): string[] {
  const lines: string[] = [];

  if (displayStatus === 'active') {
    lines.push(__('This campaign is live. Archiving stops it earning immediately.', 'yayloyalty'));
  } else if (displayStatus === 'scheduled') {
    lines.push(
      sprintf(
        /* translators: %s: the date the campaign starts. */
        __('It is scheduled to start %s. Archiving stops it from starting.', 'yayloyalty'),
        parseWPDate(campaign.starts_at ?? undefined),
      ),
    );
  }

  lines.push(
    __(
      'Points already awarded and customer history are kept. You can restore it from <link>Archived</link>.',
      'yayloyalty',
    ),
  );

  return lines;
}
