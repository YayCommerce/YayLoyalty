import { formatSignedPoints } from '@/lib/helpers/ledger.helper';
import { parseWPDate, parseWPTime, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { ActivityEntry } from '@/lib/schema/activity.schema';
import {
  activityAccountCreatedSubLine,
  activityBalanceEmailSubLine,
  activityExpiringSoonEmailSubLine,
  activityExpiryResetSubLine,
  activityPointsAdjustedSubLine,
  activityRewardRedeemedSubLine,
  activityTierChangedSubLine,
  ACTIVITY_TITLE_ACCOUNT_CREATED,
  ACTIVITY_TITLE_BALANCE_EMAIL_SENT,
  ACTIVITY_TITLE_EXPIRING_SOON_EMAIL_SENT,
  ACTIVITY_TITLE_EXPIRY_RESET,
  ACTIVITY_TITLE_POINTS_ADJUSTED,
  ACTIVITY_TITLE_REWARD_REDEEMED,
  ACTIVITY_TITLE_TIER_CHANGED,
  AUTOMATIC_ACTOR_LABEL,
  CUSTOMER_ACTOR_LABEL,
} from './customer-drawer.copy';

export type ActivityRow = {
  date: string;
  time: string;
  title: string;
  subLine: string;
  actorLabel: string;
  isStaffOnly: boolean;
  isEmail: boolean;
};

const EMAIL_EVENT_TYPES = new Set<ActivityEntry['event_type']>(['balance_email_sent', 'expiring_soon_email_sent']);

/**
 * Row → what the activity table renders. Pure — no fetch, no React. Mirrors
 * `customer-drawer.ledger-rows.ts`'s own shape: every sentence is composed
 * here from `event_type` + `data`, never stored as a string on the fixture
 * (see `DrawerActivity.php`'s docblock).
 */
export function toActivityRow(entry: ActivityEntry): ActivityRow {
  const occurred = utcToDate(entry.occurred_at);

  return {
    date: parseWPDate(occurred),
    time: parseWPTime(occurred),
    title: titleFor(entry.event_type),
    subLine: subLineFor(entry, occurred),
    actorLabel: actorLabelFor(entry),
    isStaffOnly: entry.staff_only,
    isEmail: EMAIL_EVENT_TYPES.has(entry.event_type),
  };
}

function titleFor(eventType: ActivityEntry['event_type']): string {
  switch (eventType) {
    case 'account_created':
      return ACTIVITY_TITLE_ACCOUNT_CREATED();
    case 'points_adjusted':
      return ACTIVITY_TITLE_POINTS_ADJUSTED();
    case 'tier_changed':
      return ACTIVITY_TITLE_TIER_CHANGED();
    case 'expiry_reset':
      return ACTIVITY_TITLE_EXPIRY_RESET();
    case 'reward_redeemed':
      return ACTIVITY_TITLE_REWARD_REDEEMED();
    case 'balance_email_sent':
      return ACTIVITY_TITLE_BALANCE_EMAIL_SENT();
    case 'expiring_soon_email_sent':
      return ACTIVITY_TITLE_EXPIRING_SOON_EMAIL_SENT();
  }
}

/**
 * `data` is `Record<string, unknown>` on the wire — every accessor defends
 * with a fallback rather than trusting the fixture's shape blindly, the
 * same caution `describeLedgerEntry()` takes with ledger rows.
 */
function str(data: Record<string, unknown>, key: string): string {
  const value = data[key];
  return typeof value === 'string' ? value : '';
}
function num(data: Record<string, unknown>, key: string): number {
  const value = data[key];
  return typeof value === 'number' ? value : 0;
}
function bool(data: Record<string, unknown>, key: string): boolean {
  return data[key] === true;
}

function subLineFor(entry: ActivityEntry, occurred: Date): string {
  const { data } = entry;

  switch (entry.event_type) {
    case 'account_created':
      return activityAccountCreatedSubLine(str(data, 'campaign'), formatPoints(num(data, 'points')));
    case 'points_adjusted':
      return activityPointsAdjustedSubLine(formatSignedPoints(num(data, 'points')), str(data, 'note'));
    case 'tier_changed':
      return activityTierChangedSubLine(
        str(data, 'from_tier'),
        str(data, 'to_tier'),
        bool(data, 'automatic'),
        formatPoints(num(data, 'threshold')),
      );
    case 'expiry_reset':
      return activityExpiryResetSubLine(
        parseWPDate(utcToDate(str(data, 'from'))),
        parseWPDate(utcToDate(str(data, 'to'))),
        str(data, 'note'),
      );
    case 'reward_redeemed':
      // Not `formatPoints()` — an order number is an id, not a points figure,
      // and grouped-thousands formatting would print "order #4,098" instead
      // of "order #4098". Matches `describeLedgerEntry()`'s own order-number
      // formatting for the same field (`ledger.helper.ts`).
      return activityRewardRedeemedSubLine(str(data, 'reward'), String(num(data, 'order_id')));
    case 'balance_email_sent':
      return activityBalanceEmailSubLine(str(data, 'subject'));
    case 'expiring_soon_email_sent': {
      // `deadline` is derived client-side (occurred_at + window_days) — not a
      // fixture field, so there is nothing to keep in sync when it changes.
      const windowDays = num(data, 'window_days');
      const deadline = new Date(occurred.getTime() + windowDays * 24 * 60 * 60 * 1000);

      return activityExpiringSoonEmailSubLine(formatPoints(windowDays), parseWPDate(deadline));
    }
  }
}

function actorLabelFor(entry: ActivityEntry): string {
  if (entry.actor_kind === 'staff') return entry.actor_name ?? '';
  if (entry.actor_kind === 'automatic') return AUTOMATIC_ACTOR_LABEL();
  return CUSTOMER_ACTOR_LABEL();
}
