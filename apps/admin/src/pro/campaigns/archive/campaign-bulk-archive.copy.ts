import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { Campaign, CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { statusLabel } from '../campaign-labels';
import { CampaignArchiveCase, pointsPhrase } from './campaign-archive.copy';

/**
 * Copy for CampaignBulkArchiveDialog. Figures are the ones the single dialog
 * decides its case from, never fetched per row: a bulk dialog that waits on
 * one request per campaign stops being a summary.
 */

/** One selected campaign, placed by resolveArchiveCase() exactly as its own dialog would place it. */
export interface BulkArchiveRow {
  campaign: Campaign;
  displayStatus: CampaignDisplayStatus;
  archiveCase: CampaignArchiveCase;
  lifetimePoints: number;
}

/** The line under the title. Attention means archiving stops something: it is earning, or about to. */
export function bulkContextLine(rows: BulkArchiveRow[]): string {
  const attention = rows.filter((row) => row.displayStatus === 'active' || row.displayStatus === 'scheduled').length;

  if (attention === 0) {
    return __('None of them is earning right now', 'yayloyalty');
  }

  return sprintf(
    /* translators: %d: number of selected campaigns that are live or scheduled. */
    _n('%d of them needs your attention', '%d of them need your attention', attention, 'yayloyalty'),
    attention,
  );
}

/**
 * "What this changes, in total": one line per status present, the count
 * leading. Lines carry <b> for createInterpolateElement.
 */
export function bulkSummaryLines(rows: BulkArchiveRow[]): string[] {
  const count = (status: CampaignDisplayStatus) => rows.filter((row) => row.displayStatus === status).length;
  const lines: string[] = [];

  const live = count('active');
  if (live > 0) {
    // Not every trigger has a default to fall back to (only purchase/signup/review do), so a
    // roll-up covering a mixed selection states only what is true for all of them.
    lines.push(
      sprintf(
        /* translators: %d: number of selected campaigns that are live right now. */
        _n(
          '<b>%d campaign is live</b> and stops earning immediately.',
          '<b>%d campaigns are live</b> and stop earning immediately.',
          live,
          'yayloyalty',
        ),
        live,
      ),
    );
  }

  const scheduled = count('scheduled');
  if (scheduled > 0) {
    lines.push(
      sprintf(
        /* translators: %d: number of selected campaigns that have not started yet. */
        _n(
          '<b>%d campaign is scheduled</b> and will not start.',
          '<b>%d campaigns are scheduled</b> and will not start.',
          scheduled,
          'yayloyalty',
        ),
        scheduled,
      ),
    );
  }

  const ended = count('ended');
  if (ended > 0) {
    lines.push(
      sprintf(
        /* translators: %d: number of selected campaigns that have ended. */
        _n(
          '<b>%d campaign has already ended</b> and changes nothing today.',
          '<b>%d campaigns have already ended</b> and change nothing today.',
          ended,
          'yayloyalty',
        ),
        ended,
      ),
    );
  }

  const paused = count('paused');
  if (paused > 0) {
    lines.push(
      sprintf(
        /* translators: %d: number of selected campaigns that are paused. */
        _n(
          '<b>%d campaign is paused</b> and changes nothing today.',
          '<b>%d campaigns are paused</b> and change nothing today.',
          paused,
          'yayloyalty',
        ),
        paused,
      ),
    );
  }

  const draft = count('draft');
  if (draft > 0) {
    lines.push(
      sprintf(
        /* translators: %d: number of selected campaigns that are drafts. */
        _n(
          '<b>%d campaign is a draft</b> and changes nothing today.',
          '<b>%d campaigns are drafts</b> and change nothing today.',
          draft,
          'yayloyalty',
        ),
        draft,
      ),
    );
  }

  return lines;
}

/** Closes the summary: points already awarded, which neither archiving nor deleting touches. */
export function bulkTotalLine(rows: BulkArchiveRow[]): string {
  const total = rows.reduce((sum, row) => sum + row.lifetimePoints, 0);

  // Gated on is_deletable, not on the total being 0: a refunded-to-zero campaign nets 0 points
  // but still has ledger rows naming it, so "nothing refers to them" would be false for it
  // specifically — same reason resolveArchiveCase() takes is_deletable, not a points check.
  if (rows.every((row) => row.campaign.is_deletable)) {
    return __('None of them has awarded a point, so nothing in customer history refers to them.', 'yayloyalty');
  }

  return sprintf(
    /* translators: 1: number of selected campaigns; 2: total points they have awarded, formatted. */
    _n(
      'Across all %1$d, %2$s point already awarded stays exactly where it is, and every ledger row keeps naming its campaign.',
      'Across all %1$d, %2$s points already awarded stay exactly where they are, and every ledger row keeps naming its campaign.',
      total,
      'yayloyalty',
    ),
    rows.length,
    formatPoints(total),
  );
}

/** A listed row's badge: "Live now" for a campaign earning right now, its status otherwise. */
export function bulkRowBadge(displayStatus: CampaignDisplayStatus): string {
  return displayStatus === 'active' ? __('Live now', 'yayloyalty') : statusLabel(displayStatus);
}

/** A listed row's figures, e.g. "96,800 points lifetime · starts 27 Nov". */
export function bulkRowFigures(row: BulkArchiveRow): string {
  if (row.archiveCase === 'delete') {
    return sprintf(
      /* translators: %s: the date the campaign was created. */
      __('0 points · created %s', 'yayloyalty'),
      parseWPDate(row.campaign.created_at),
    );
  }

  const parts: string[] = [
    sprintf(
      /* translators: %s: points phrase, e.g. "96,800 points". */
      __('%s lifetime', 'yayloyalty'),
      pointsPhrase(row.lifetimePoints),
    ),
  ];

  const tail = statusTail(row);

  if (tail) {
    parts.push(tail);
  }

  return parts.join(' · ');
}

/** No 'active' case: there is no windowed figure to add beyond the lifetime one bulkRowFigures() already shows. */
function statusTail({ campaign, displayStatus }: BulkArchiveRow): string | null {
  switch (displayStatus) {
    case 'scheduled':
      return sprintf(
        /* translators: %s: the date the campaign starts. */
        __('starts %s', 'yayloyalty'),
        parseWPDate(campaign.starts_at ?? undefined),
      );
    case 'ended':
      return sprintf(
        /* translators: %s: the date the campaign ended. */
        __('ended %s', 'yayloyalty'),
        parseWPDate(campaign.ends_at ?? undefined),
      );
    default:
      return null;
  }
}
