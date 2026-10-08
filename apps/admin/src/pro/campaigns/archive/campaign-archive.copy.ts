import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import type { Campaign, CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { givesLabel, isBuiltinCampaignActive, statusLabel } from '../campaign-labels';

/**
 * Case resolution and copy for CampaignArchiveDialog, plus the live-campaign
 * lines CampaignPauseDialog shares. Sentences are written out per trigger
 * rather than splicing a noun into a template, same reason as
 * campaign-performance.copy.ts.
 */

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

/** Where a not-yet-finished campaign stands, e.g. "Live right now" or "Starts 27 Nov". */
export function liveStanding(campaign: Campaign, displayStatus: CampaignDisplayStatus): string {
  switch (displayStatus) {
    case 'scheduled':
      return sprintf(
        /* translators: %s: the date the campaign starts. */
        __('Starts %s', 'yayloyalty'),
        parseWPDate(campaign.starts_at ?? undefined),
      );
    case 'paused':
      return __('Paused', 'yayloyalty');
    case 'draft':
      return __('Draft', 'yayloyalty');
    case 'active':
      return __('Live right now', 'yayloyalty');
    default:
      return statusLabel(displayStatus);
  }
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

/** What ends when an active campaign stops earning; the first line is always the stop-earning one. */
export function activeStops(campaign: Campaign, fallback: Campaign | undefined): string[] {
  const gives = givesLabel(campaign.trigger, campaign.config);
  const lines = [stopEarningLine(campaign.trigger, gives)];

  if (fallback) {
    lines.push(
      isBuiltinCampaignActive(fallback)
        ? sprintf(
            /* translators: 1: the built-in campaign's name; 2: what it gives, e.g. "1 pt per $1.00". */
            __('What it matched falls back to %1$s at %2$s', 'yayloyalty'),
            fallback.name,
            givesLabel(fallback.trigger, fallback.config),
          )
        : sprintf(
            /* translators: %s: the built-in campaign's name. */
            __('What it matched earns nothing: %s is not active', 'yayloyalty'),
            fallback.name,
          ),
    );
  }

  if (campaign.trigger === 'purchase') {
    lines.push(__('The storefront stops showing its rate on the products it covers', 'yayloyalty'));
  }

  return lines;
}

function stopEarningLine(trigger: Campaign['trigger'], gives: string): string {
  switch (trigger) {
    case 'purchase':
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "×2 points". */
        __('Orders stop earning %s from it immediately', 'yayloyalty'),
        gives,
      );
    case 'signup':
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "200 points". */
        __('New signups stop earning %s from it immediately', 'yayloyalty'),
        gives,
      );
    case 'review':
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "50 points". */
        __('Reviews stop earning %s from it immediately', 'yayloyalty'),
        gives,
      );
    default:
      return sprintf(
        /* translators: %s: what the campaign gives, e.g. "500 points". */
        __('Customers stop earning %s from it immediately', 'yayloyalty'),
        gives,
      );
  }
}
