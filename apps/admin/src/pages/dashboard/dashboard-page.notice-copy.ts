import { __, _n, sprintf } from '@wordpress/i18n';

import { CAP } from '@/lib/access';
import { formatStoredDate, parseWPDay, parseWPTime, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { parseNoticeData, type TypedNotice } from '@/lib/schema/dashboard-notice.schema';
import type { DashboardNotice } from '@/lib/schema/dashboard.schema';
import { scrollToAnomalyPanel, type NoticeAction } from './dashboard-page.notice-actions';

/**
 * Every word of every notice, from the server's structured data.
 * Thresholds are quoted from data, never hard-coded; the anomaly copy says
 * a campaign was edited and never claims what changed (no diff exists).
 */

export type NoticeCopy = {
  title: string;
  body: string;
  primary?: NoticeAction;
  secondary?: NoticeAction;
};

export function noticeCopy(notice: DashboardNotice): NoticeCopy | null {
  const typed = parseNoticeData(notice);

  return typed ? copyFor(typed) : null;
}

function copyFor(n: TypedNotice): NoticeCopy {
  switch (n.key) {
    case 'negative_balance':
      return {
        title: sprintf(
          /* translators: %s: number of customers, formatted. */
          _n(
            '%s customer has a negative balance.',
            '%s customers have a negative balance.',
            n.data.count,
            'yayloyalty',
          ),
          formatPoints(n.data.count),
        ),
        body: __(
          'A refund clawback can push a balance below zero. Review each one and decide whether to correct it.',
          'yayloyalty',
        ),
        secondary: { kind: 'link', label: __('Review', 'yayloyalty'), href: '/customers?balance_range=negative' },
      };

    case 'default_campaign_paused':
      return {
        title: __("Customers aren't earning points on purchases", 'yayloyalty'),
        body: sprintf(
          /* translators: 1: the date the campaign was paused; 2: the "N orders have completed" phrase. */
          __('The default campaign has been paused since %1$s. %2$s and awarded nothing.', 'yayloyalty'),
          formatStoredDate(n.data.paused_since),
          sprintf(
            /* translators: %s: number of orders completed since the pause, formatted. */
            _n('%s order has completed', '%s orders have completed', n.data.orders_without_points, 'yayloyalty'),
            formatPoints(n.data.orders_without_points),
          ),
        ),
        // TODO(dashboard-notices): wire the reactivation mutation CampaignTable already uses.
        primary: {
          kind: 'disabled',
          label: __('Switch it back on', 'yayloyalty'),
          reason: __('Reactivation from the dashboard is coming soon. Open Campaigns to resume it.', 'yayloyalty'),
          cap: CAP.MANAGE_CAMPAIGNS,
        },
        secondary: { kind: 'link', label: __('Review', 'yayloyalty'), href: '/campaigns', cap: CAP.MANAGE_CAMPAIGNS },
      };

    case 'points_jump': {
      const edits = n.data.what_changed;
      const base = sprintf(
        /* translators: 1: points issued yesterday; 2: yesterday's date; 3: the 30-day daily average, in points. */
        __('%1$s points on %2$s against a 30-day average of %3$s.', 'yayloyalty'),
        formatPoints(n.data.yesterday_points),
        parseWPDay(n.data.yesterday),
        formatPoints(n.data.daily_average),
      );
      const cause =
        edits.length === 0
          ? __('No campaign was edited that day.', 'yayloyalty')
          : edits.length === 1
            ? sprintf(
                /* translators: %s: the time the campaign was edited. */
                __('One campaign was edited at %s.', 'yayloyalty'),
                parseWPTime(utcToDate(edits[0].updated_at)),
              )
            : sprintf(
                /* translators: %d: number of campaigns edited that day. */
                __('%d campaigns were edited that day.', 'yayloyalty'),
                edits.length,
              );

      return {
        title: sprintf(
          /* translators: %s: how many times the daily average, e.g. "3.2". */
          __('Points issued yesterday were %s× your daily average', 'yayloyalty'),
          n.data.multiple.toLocaleString(),
        ),
        body: sprintf(
          /* translators: 1: the figures sentence, 2: the cause sentence. */
          __('%1$s %2$s', 'yayloyalty'),
          base,
          cause,
        ),
        primary: { kind: 'button', label: __('See what changed', 'yayloyalty'), onClick: scrollToAnomalyPanel },
        secondary:
          edits.length > 0
            ? {
                kind: 'link',
                label: __('Review the campaign', 'yayloyalty'),
                href: '/campaigns',
                cap: CAP.MANAGE_CAMPAIGNS,
              }
            : undefined,
      };
    }

    case 'mail_failures':
      return {
        title: sprintf(
          /* translators: 1: number of failed emails, formatted; 2: the date the failures began. */
          _n(
            '%s loyalty email failed to send since %s',
            '%s loyalty emails failed to send since %s',
            n.data.failed,
            'yayloyalty',
          ),
          formatPoints(n.data.failed),
          formatStoredDate(n.data.since),
        ),
        body: __("This is the site's mail, not just ours — order confirmations may be failing too.", 'yayloyalty'),
        secondary: { kind: 'link', label: __('Check mail settings', 'yayloyalty'), href: '/settings/general' },
      };

    case 'balance_rebuild':
      return {
        title: sprintf(
          /* translators: 1: balances checked so far, formatted; 2: all balances to check, formatted. */
          __('Balances are being rebuilt — %1$s of %2$s checked', 'yayloyalty'),
          formatPoints(n.data.processed),
          formatPoints(n.data.total),
        ),
        body: __(
          'Figures on this page are as they were when the rebuild started and will not update until it finishes.',
          'yayloyalty',
        ),
        secondary: { kind: 'link', label: __('Watch the log', 'yayloyalty'), href: '/customers' },
      };
  }
}

/** One-line summary for the collapsed part of the strip: "a negative balance to review". */
export function noticeSummary(notice: DashboardNotice): string {
  switch (notice.key) {
    case 'negative_balance':
      return __('a negative balance to review', 'yayloyalty');
    case 'default_campaign_paused':
      return __('the default campaign is paused', 'yayloyalty');
    case 'points_jump':
      return __('a jump in points issued', 'yayloyalty');
    case 'mail_failures':
      return __('mail failures', 'yayloyalty');
    case 'balance_rebuild':
      return __('a balance rebuild in progress', 'yayloyalty');
    default:
      // Unknown keys never reach the strip; this satisfies the open wire type.
      return notice.key;
  }
}
