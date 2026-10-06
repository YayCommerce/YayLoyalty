import { formatStoredDate, parseWPDate, parseWPTime, utcToDate } from '@/lib/helpers/format.helper';
import { formatSignedPoints } from '@/lib/helpers/ledger.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { ActivityEntry, ActivityEventType } from '@/lib/schema/activity.schema';
import {
  ACTIVITY_TITLE_MEMBERSHIP_STARTED,
  ACTIVITY_TITLE_ACCOUNT_DELETED,
  ACTIVITY_TITLE_BALANCE_EMAIL_SENT,
  ACTIVITY_TITLE_EXPIRING_SOON_EMAIL_SENT,
  ACTIVITY_TITLE_EXPIRY_RESET,
  ACTIVITY_TITLE_POINTS_ADJUSTED,
  ACTIVITY_TITLE_REWARD_REDEEMED,
  ACTIVITY_TITLE_TIER_CHANGED,
  activityMembershipStartedSubLine,
  activityAccountDeletedSubLine,
  activityBalanceEmailSubLine,
  activityExpiringSoonEmailSubLine,
  activityExpiryResetSubLine,
  activityPointsAdjustedSubLine,
  activityRewardRedeemedSubLine,
  activityTierChangedSubLine,
  AUTOMATIC_ACTOR_LABEL,
  CUSTOMER_ACTOR_LABEL,
} from './customer-drawer.activity.copy';

export type ActivityRow = {
  date: string;
  time: string;
  title: string;
  subLine: string;
  actorLabel: string;
  isStaffOnly: boolean;
  isEmail: boolean;
};

const EMAIL_EVENT_TYPES = new Set<ActivityEventType>(['balance_email_sent', 'expiring_soon_email_sent']);

/** Maps an activity entry to its table row. Every sentence is composed here from `event_type` and `data`. */
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

function titleFor(eventType: ActivityEventType): string {
  switch (eventType) {
    case 'membership_started':
      return ACTIVITY_TITLE_MEMBERSHIP_STARTED();
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
    case 'account_deleted':
      return ACTIVITY_TITLE_ACCOUNT_DELETED();
  }
}

/** `data` is untyped on the wire, so each accessor falls back rather than trusting its shape. */
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
    case 'membership_started':
      return activityMembershipStartedSubLine(formatPoints(num(data, 'points')));
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
        formatStoredDate(str(data, 'from')),
        formatStoredDate(str(data, 'to')),
        str(data, 'note'),
      );
    case 'reward_redeemed':
      // Not formatPoints(): an order number is an id, and grouping would print "order #4,098".
      return activityRewardRedeemedSubLine(str(data, 'reward'), String(num(data, 'order_id')));
    case 'balance_email_sent':
      return activityBalanceEmailSubLine(str(data, 'subject'));
    case 'expiring_soon_email_sent': {
      // The API does not send a deadline: it is occurred_at plus window_days.
      const windowDays = num(data, 'window_days');
      const deadline = new Date(occurred.getTime() + windowDays * 24 * 60 * 60 * 1000);

      return activityExpiringSoonEmailSubLine(formatPoints(windowDays), parseWPDate(deadline));
    }
    case 'account_deleted':
      return activityAccountDeletedSubLine(str(data, 'email'), formatPoints(num(data, 'balance_at_deletion')));
  }
}

function actorLabelFor(entry: ActivityEntry): string {
  if (entry.actor_kind === 'staff') return entry.actor_name ?? '';
  if (entry.actor_kind === 'automatic') return AUTOMATIC_ACTOR_LABEL();
  return CUSTOMER_ACTOR_LABEL();
}
