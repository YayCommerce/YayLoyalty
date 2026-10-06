import { __, _n, _x, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import {
  Campaign,
  CampaignDisplayStatus,
  findBuiltinCampaign,
  resolveCampaignDisplayStatus,
} from '@/lib/schema/campaign.schema';
import { activeStops, liveStanding } from '../archive/campaign-archive.copy';
import { givesLabel } from '../campaign-labels';

/**
 * Copy for CampaignPauseDialog. Per-trigger sentences are written out whole,
 * same reason as campaign-archive.copy.ts.
 */

/** The line under the dialog title, naming what the campaign gives rather than its conditions. */
export function pauseContextLine(campaign: Campaign, displayStatus: CampaignDisplayStatus): string {
  const gives = givesLabel(campaign.trigger, campaign.config);

  if (campaign.is_builtin) {
    return sprintf(
      /* translators: 1: the campaign's priority; 2: what it gives, e.g. "1 pt per $1.00". */
      __('The default campaign · priority %1$d · %2$s', 'yayloyalty'),
      campaign.priority,
      gives,
    );
  }

  return sprintf(
    /* translators: 1: where the campaign stands, e.g. "Live right now"; 2: its priority; 3: what it gives, e.g. "×2 points". */
    _x('%1$s · priority %2$d · %3$s', 'pause dialog subtitle', 'yayloyalty'),
    liveStanding(campaign, displayStatus),
    campaign.priority,
    gives,
  );
}

/** The marked block: what stops, then the lines that explain it. */
export interface PauseStops {
  headline: string;
  lines: string[];
}

/**
 * `campaigns` is the whole list, for the built-in fallback and for the other
 * campaigns sharing this trigger; undefined while it loads, which leaves out
 * only the lines that depend on it.
 */
export function pauseStops(
  campaign: Campaign,
  displayStatus: CampaignDisplayStatus,
  campaigns: Campaign[] | undefined,
): PauseStops {
  if (displayStatus === 'scheduled') {
    return {
      headline: sprintf(
        /* translators: %s: the date the campaign was due to start. */
        __('It will not start on %s', 'yayloyalty'),
        parseWPDate(campaign.starts_at ?? undefined),
      ),
      lines: [],
    };
  }

  if (!campaign.is_builtin) {
    const [headline, ...lines] = activeStops(campaign, findBuiltinCampaign(campaigns, campaign.trigger));

    return { headline, lines };
  }

  const others = campaigns
    ?.filter(
      (candidate) =>
        candidate.id !== campaign.id &&
        candidate.trigger === campaign.trigger &&
        resolveCampaignDisplayStatus(candidate.status, candidate.starts_at, candidate.ends_at) === 'active',
    )
    .sort((a, b) => b.priority - a.priority);

  return defaultCampaignStops(campaign.trigger, others);
}

/**
 * A default campaign is the fallback for its trigger, so pausing it stops
 * everything no other campaign matches. `others` are the active campaigns
 * sharing its trigger, highest priority first.
 */
function defaultCampaignStops(trigger: Campaign['trigger'], others: Campaign[] | undefined): PauseStops {
  const count = others?.length ?? 0;
  const topName = others?.[0]?.name ?? '';

  switch (trigger) {
    case 'purchase':
      return {
        headline: __('Customers stop earning points on purchases', 'yayloyalty'),
        lines: withOthersLine(
          __(
            'This is the fallback campaign, so pausing it means an order that matches nothing else earns nothing at all.',
            'yayloyalty',
          ),
          others,
          count > 0
            ? sprintf(
                /* translators: 1: number of other active purchase campaigns; 2: the name of the highest-priority one. */
                _n(
                  'Orders that match %1$d other active purchase campaign, “%2$s”, still earn.',
                  'Orders that match %1$d other active purchase campaigns, such as “%2$s”, still earn.',
                  count,
                  'yayloyalty',
                ),
                count,
                topName,
              )
            : __('No other purchase campaign is active right now, so no order earns points.', 'yayloyalty'),
        ),
      };
    case 'signup':
      return {
        headline: __('New customers stop earning points for signing up', 'yayloyalty'),
        lines: withOthersLine(
          __(
            'This is the default signup campaign, so pausing it means a signup that matches nothing else earns nothing.',
            'yayloyalty',
          ),
          others,
          count > 0
            ? sprintf(
                /* translators: 1: number of other active signup campaigns; 2: the name of the highest-priority one. */
                _n(
                  'Signups that match %1$d other active signup campaign, “%2$s”, still earn.',
                  'Signups that match %1$d other active signup campaigns, such as “%2$s”, still earn.',
                  count,
                  'yayloyalty',
                ),
                count,
                topName,
              )
            : __('No other signup campaign is active right now, so no signup earns points.', 'yayloyalty'),
        ),
      };
    case 'review':
      return {
        headline: __('Customers stop earning points for reviews', 'yayloyalty'),
        lines: withOthersLine(
          __(
            'This is the default review campaign, so pausing it means a review that matches nothing else earns nothing.',
            'yayloyalty',
          ),
          others,
          count > 0
            ? sprintf(
                /* translators: 1: number of other active review campaigns; 2: the name of the highest-priority one. */
                _n(
                  'Reviews that match %1$d other active review campaign, “%2$s”, still earn.',
                  'Reviews that match %1$d other active review campaigns, such as “%2$s”, still earn.',
                  count,
                  'yayloyalty',
                ),
                count,
                topName,
              )
            : __('No other review campaign is active right now, so no review earns points.', 'yayloyalty'),
        ),
      };
    default:
      // Built-ins exist for the three Lite triggers only.
      return {
        headline: __('Customers stop earning points from this campaign', 'yayloyalty'),
        lines: [],
      };
  }
}

/** The second line only once the list has loaded: "no other campaign is active" on a guess would be wrong. */
function withOthersLine(first: string, others: Campaign[] | undefined, othersLine: string): string[] {
  return others === undefined ? [first] : [first, othersLine];
}

/** The reassurance under the blocks: pausing loses nothing. */
export function resumeLine(trigger: Campaign['trigger'], displayStatus: CampaignDisplayStatus): string {
  if (displayStatus === 'scheduled') {
    return __(
      'Nothing is lost: the campaign keeps its settings and its history, and switching it back on puts it back on its schedule.',
      'yayloyalty',
    );
  }

  switch (trigger) {
    case 'purchase':
      return __(
        'Nothing is lost: the campaign keeps its settings and its history, and switching it back on resumes earning on the next order.',
        'yayloyalty',
      );
    case 'signup':
      return __(
        'Nothing is lost: the campaign keeps its settings and its history, and switching it back on resumes earning on the next signup.',
        'yayloyalty',
      );
    case 'review':
      return __(
        'Nothing is lost: the campaign keeps its settings and its history, and switching it back on resumes earning on the next review.',
        'yayloyalty',
      );
    default:
      return __(
        'Nothing is lost: the campaign keeps its settings and its history, and switching it back on resumes earning.',
        'yayloyalty',
      );
  }
}
