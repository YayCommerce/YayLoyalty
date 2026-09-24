import { date as wpDate } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, parseWPDate, parseWPRelativeDate } from '@/lib/helpers/format.helper';
import { formatPoints, pointsPhrase } from '@/lib/helpers/points.helper';
import type { CampaignPerformance } from '@/lib/schema/campaign-performance.schema';
import type { Campaign, CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { conditionsLabel, givesLabel, scheduleLabel, statusLabel } from '../campaign-labels';

/**
 * Case resolution and trigger-aware copy for CampaignArchiveDialog. Sentences
 * are written out per trigger rather than splicing a noun into a template,
 * same reason as campaign-performance.copy.ts.
 */

/** Which archive dialog a campaign gets. Points decide first, then status. */
export type CampaignArchiveCase = 'delete' | 'finished' | 'live';

/**
 * `isDeletable` is the server's own delete_item() guard (not built-in, zero
 * ledger rows), restated for the client — never inferred from a points
 * total here, since a campaign earned-then-fully-refunded nets 0 points but
 * still has rows naming it, and offering a permanent delete on that guess
 * is the one mistake this dialog cannot undo.
 */
export function resolveArchiveCase(displayStatus: CampaignDisplayStatus, isDeletable: boolean): CampaignArchiveCase {
  if (isDeletable) {
    return 'delete';
  }

  return displayStatus === 'ended' ? 'finished' : 'live';
}

/** What the figures block says; `detail` is optional because not every trigger has one. */
export interface ArchiveFigures {
  headline: string;
  detail?: string;
}

/**
 * Figures for an ended campaign, from its performance summary. `totals` is
 * lifetime (see CampaignPerformanceRestController's own docblock), so
 * `detail` states liability only — never a date range, which would now be
 * the chart's own narrower 30-day window, not the span these lifetime
 * figures cover. The schedule itself is already in contextLine(), right
 * above this in the dialog.
 */
export function finishedFigures(trigger: Campaign['trigger'], performance: CampaignPerformance): ArchiveFigures {
  const { totals } = performance;

  if (trigger === 'purchase') {
    return {
      headline: sprintf(
        /* translators: 1: points phrase, e.g. "84,200 points"; 2: orders phrase, e.g. "612 orders". */
        __('Paid out %1$s across %2$s', 'yayloyalty'),
        pointsPhrase(totals.points_issued),
        ordersPhrase(totals.orders_affected ?? 0),
      ),
      detail: liabilityDetail(totals.liability_amount),
    };
  }

  return {
    headline: sprintf(
      /* translators: 1: points phrase, e.g. "9,300 points"; 2: customers phrase, e.g. "186 customers". */
      __('Paid out %1$s to %2$s', 'yayloyalty'),
      pointsPhrase(totals.points_issued),
      customersPhrase(totals.customers_earned ?? 0),
    ),
  };
}

/**
 * Figures for a campaign that is earning right now. Today is looked up by
 * site date, not taken as the window's last day: a dated campaign's window
 * can end in the future. `totals` is lifetime, so the sentence says "so
 * far" rather than naming a date range — see finishedFigures()'s own note.
 */
export function earningFigures(trigger: Campaign['trigger'], performance: CampaignPerformance): ArchiveFigures {
  const { totals } = performance;
  const todaySiteDate = wpDate('Y-m-d', undefined, undefined);
  const today = performance.daily.find((day) => day.date === todaySiteDate)?.points ?? 0;

  const headline =
    today > 0
      ? __('This campaign is earning points today', 'yayloyalty')
      : __('This campaign is live right now', 'yayloyalty');

  if (trigger === 'purchase') {
    return {
      headline,
      detail: sprintf(
        /* translators: 1: points phrase for today; 2: points phrase issued so far; 3: orders phrase. */
        __('%1$s today · %2$s across %3$s so far.', 'yayloyalty'),
        pointsPhrase(today),
        pointsPhrase(totals.points_issued),
        ordersPhrase(totals.orders_affected ?? 0),
      ),
    };
  }

  return {
    headline,
    detail: sprintf(
      /* translators: 1: points phrase for today; 2: points phrase issued so far; 3: customers phrase. */
      __('%1$s today · %2$s to %3$s so far.', 'yayloyalty'),
      pointsPhrase(today),
      pointsPhrase(totals.points_issued),
      customersPhrase(totals.customers_earned ?? 0),
    ),
  };
}

/** Fallback for triggers with no performance summary, and for campaigns not earning right now. */
export function lifetimeFigures(lifetimePoints: number): ArchiveFigures {
  return {
    headline: sprintf(
      /* translators: %s: points phrase, e.g. "57,800 points". */
      __('Has paid out %s so far', 'yayloyalty'),
      pointsPhrase(lifetimePoints),
    ),
  };
}

function ordersPhrase(count: number): string {
  /* translators: %s: number of orders, formatted. */
  return sprintf(_n('%s order', '%s orders', count, 'yayloyalty'), formatPoints(count));
}

function customersPhrase(count: number): string {
  /* translators: %s: number of customers, formatted. */
  return sprintf(_n('%s customer', '%s customers', count, 'yayloyalty'), formatPoints(count));
}

/** The line under the dialog title. */
export function contextLine(
  campaign: Campaign,
  archiveCase: CampaignArchiveCase,
  displayStatus: CampaignDisplayStatus,
): string {
  if (archiveCase === 'delete') {
    return sprintf(
      /* translators: %s: the date the campaign was created. */
      __('Created %s · never awarded points', 'yayloyalty'),
      parseWPDate(campaign.created_at),
    );
  }

  if (archiveCase === 'finished') {
    return sprintf(
      /* translators: 1: the campaign's schedule, e.g. "1 Jun – 15 Jun"; 2: relative time since it ended, e.g. "3 months ago". */
      __('Ran %1$s · ended %2$s', 'yayloyalty'),
      scheduleLabel(campaign.starts_at, campaign.ends_at),
      campaign.ends_at ? parseWPRelativeDate(campaign.ends_at) : '',
    );
  }

  return sprintf(
    /* translators: 1: where the campaign stands, e.g. "Live right now"; 2: its priority; 3: its conditions summary. */
    __('%1$s · priority %2$d · %3$s', 'yayloyalty'),
    liveStanding(campaign, displayStatus),
    campaign.priority,
    conditionsLabel(campaign.conditions),
  );
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

/** Left column of every archive dialog: what survives. */
export function keepsLines(lifetimePoints: number): string[] {
  return [
    sprintf(
      /* translators: %s: points phrase, e.g. "12,400 points". */
      __('The %s already awarded', 'yayloyalty'),
      pointsPhrase(lifetimePoints),
    ),
    __('Customer history, still naming this campaign', 'yayloyalty'),
    __('Its performance figures in reports', 'yayloyalty'),
  ];
}

/** Right column: what ends. `nothing` renders it muted, the reassurance case. */
export interface ArchiveStops {
  lines: string[];
  nothing: boolean;
}

/** `fallback` is the built-in campaign sharing this trigger, when one exists. */
export function stopsLines(
  campaign: Campaign,
  displayStatus: CampaignDisplayStatus,
  fallback: Campaign | undefined,
): ArchiveStops {
  switch (displayStatus) {
    case 'active':
      return { lines: activeStops(campaign, fallback), nothing: false };
    case 'ended':
      return { lines: [__('Nothing — this campaign has already ended', 'yayloyalty')], nothing: true };
    case 'paused':
      return { lines: [__('Nothing — it is already paused and not earning', 'yayloyalty')], nothing: true };
    case 'draft':
      return { lines: [__('Nothing — a draft never runs', 'yayloyalty')], nothing: true };
    case 'scheduled':
      return {
        lines: [
          sprintf(
            /* translators: %s: the date the campaign was due to start. */
            __('It will not start on %s', 'yayloyalty'),
            parseWPDate(campaign.starts_at ?? undefined),
          ),
        ],
        nothing: false,
      };
    case 'archived':
      return { lines: [__('Nothing — it is already archived', 'yayloyalty')], nothing: true };
  }
}

/** What ends when an active campaign stops earning; the first line is always the stop-earning one. */
export function activeStops(campaign: Campaign, fallback: Campaign | undefined): string[] {
  const gives = givesLabel(campaign.trigger, campaign.config);
  const lines = [stopEarningLine(campaign.trigger, gives)];

  if (fallback) {
    lines.push(
      fallback.status === 'active'
        ? sprintf(
            /* translators: 1: the built-in campaign's name; 2: what it gives, e.g. "1 point(s) per $1". */
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

/** Delete case, left column: what is removed. */
export function goesLines(campaign: Campaign): string[] {
  const lines: string[] = [__('The campaign and its settings', 'yayloyalty')];

  if (campaign.starts_at || campaign.ends_at) {
    lines.push(
      sprintf(
        /* translators: %s: the campaign's schedule, e.g. "1 Apr – 14 Apr". */
        __('Its schedule, %s', 'yayloyalty'),
        scheduleLabel(campaign.starts_at, campaign.ends_at),
      ),
    );
  }

  lines.push(__('Its row in this list', 'yayloyalty'));

  return lines;
}

/** Delete case, right column: the zeroes that make deleting safe. */
export function affectedLine(trigger: Campaign['trigger']): string {
  return trigger === 'purchase'
    ? __('Nothing — 0 points, 0 orders, 0 customers', 'yayloyalty')
    : __('Nothing — 0 points, 0 customers', 'yayloyalty');
}

function liabilityDetail(liability: number | null): string | undefined {
  if (liability === null) {
    return undefined;
  }

  return sprintf(
    /* translators: %s: liability, formatted as money. */
    __('%s of liability, still on the books until those points are spent.', 'yayloyalty'),
    parseWPCurrency(liability),
  );
}
