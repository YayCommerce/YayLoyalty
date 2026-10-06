import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPDay, utcToDate } from '@/lib/helpers/format.helper';
import type { CampaignPerformanceSkip, CampaignPerformanceTrigger } from '@/lib/schema/campaign-performance.schema';
import type { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';

/**
 * Pure, trigger-aware copy for the campaign performance page. It avoids `parseWPRelativeDate`,
 * which needs WP globals, so it runs in plain Node.
 *
 * Each sentence is written out per trigger rather than splicing a noun into a template: a
 * translator needs the whole sentence to get word order and agreement right.
 */

/** Why the page has nothing to show yet — the reason, not just an absence. */
export function emptyReason(
  trigger: CampaignPerformanceTrigger,
  displayStatus: CampaignDisplayStatus,
  startsAt: string | null,
  now: Date = new Date(),
): string {
  switch (displayStatus) {
    case 'scheduled':
      return scheduledReason(trigger, daysUntil(startsAt, now));
    case 'draft':
      return draftReason(trigger);
    case 'paused':
      return __('This campaign is paused and has not paid out yet.', 'yayloyalty');
    case 'active':
      return activeReason(trigger);
    case 'ended':
    case 'archived':
      return endedReason(trigger);
  }
}

/** `null` when `startsAt` is missing, which a `scheduled` campaign should never be. */
function daysUntil(startsAt: string | null, now: Date): number | null {
  if (!startsAt) {
    return null;
  }

  return Math.max(1, Math.ceil((utcToDate(startsAt).getTime() - now.getTime()) / 86_400_000));
}

function scheduledReason(trigger: CampaignPerformanceTrigger, days: number | null): string {
  if (days === null) {
    switch (trigger) {
      case 'purchase':
        return __('This campaign has not run yet. Figures appear here from its first matching order.', 'yayloyalty');
      case 'signup':
        return __('This campaign has not run yet. Figures appear here from its first matching signup.', 'yayloyalty');
      case 'review':
        return __('This campaign has not run yet. Figures appear here from its first matching review.', 'yayloyalty');
    }
  }

  switch (trigger) {
    case 'purchase':
      return sprintf(
        /* translators: %d: number of days until the campaign starts. */
        _n(
          'This campaign has not run yet. It starts in %d day, and figures appear here from its first matching order.',
          'This campaign has not run yet. It starts in %d days, and figures appear here from its first matching order.',
          days,
          'yayloyalty',
        ),
        days,
      );
    case 'signup':
      return sprintf(
        /* translators: %d: number of days until the campaign starts. */
        _n(
          'This campaign has not run yet. It starts in %d day, and figures appear here from its first matching signup.',
          'This campaign has not run yet. It starts in %d days, and figures appear here from its first matching signup.',
          days,
          'yayloyalty',
        ),
        days,
      );
    case 'review':
      return sprintf(
        /* translators: %d: number of days until the campaign starts. */
        _n(
          'This campaign has not run yet. It starts in %d day, and figures appear here from its first matching review.',
          'This campaign has not run yet. It starts in %d days, and figures appear here from its first matching review.',
          days,
          'yayloyalty',
        ),
        days,
      );
  }
}

function draftReason(trigger: CampaignPerformanceTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __(
        'This campaign is a draft. Figures appear here once it is published and matches its first order.',
        'yayloyalty',
      );
    case 'signup':
      return __(
        'This campaign is a draft. Figures appear here once it is published and matches its first signup.',
        'yayloyalty',
      );
    case 'review':
      return __(
        'This campaign is a draft. Figures appear here once it is published and matches its first review.',
        'yayloyalty',
      );
  }
}

function activeReason(trigger: CampaignPerformanceTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __('This campaign is running, but no order has matched it yet.', 'yayloyalty');
    case 'signup':
      return __('This campaign is running, but no signup has matched it yet.', 'yayloyalty');
    case 'review':
      return __('This campaign is running, but no review has matched it yet.', 'yayloyalty');
  }
}

function endedReason(trigger: CampaignPerformanceTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __('This campaign ran, but no order matched it.', 'yayloyalty');
    case 'signup':
      return __('This campaign ran, but no signup matched it.', 'yayloyalty');
    case 'review':
      return __('This campaign ran, but no review matched it.', 'yayloyalty');
  }
}

/** One "where the other orders went" line. */
export function skipReasonLabel(skip: CampaignPerformanceSkip): string {
  switch (skip.reason) {
    case 'higher_priority':
      return sprintf(
        /* translators: %s: the name of the higher-priority campaign that won instead. */
        __('%s — higher priority', 'yayloyalty'),
        skip.campaign_name ?? __('Another campaign', 'yayloyalty'),
      );
    case 'guest':
      return __('Guest checkout — no account, no points', 'yayloyalty');
    case 'excluded':
      return __('Excluded products only', 'yayloyalty');
  }
}

/** "27 Nov – 30 Nov 2025", or a single formatted date when `from` and `to` are the same day. */
export function windowLabel(window: { from: string; to: string }): string {
  if (window.from === window.to) {
    return parseWPDay(window.from, 'j M Y');
  }

  return sprintf(
    /* translators: 1: start date (no year), 2: end date (includes the year). */
    __('%1$s – %2$s', 'yayloyalty'),
    parseWPDay(window.from, 'j M'),
    parseWPDay(window.to, 'j M Y'),
  );
}

export function entriesTitle(trigger: CampaignPerformanceTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __('Orders this campaign paid out on', 'yayloyalty');
    case 'signup':
      return __('Signups this campaign paid out on', 'yayloyalty');
    case 'review':
      return __('Reviews this campaign paid out on', 'yayloyalty');
  }
}

export function entriesEmptyText(trigger: CampaignPerformanceTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __('No orders yet. Each order that earns from this campaign appears here, newest first.', 'yayloyalty');
    case 'signup':
      return __('No signups yet. Each signup that earns from this campaign appears here, newest first.', 'yayloyalty');
    case 'review':
      return __('No reviews yet. Each review that earns from this campaign appears here, newest first.', 'yayloyalty');
  }
}
