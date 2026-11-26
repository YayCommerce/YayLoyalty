import type { ComponentType } from 'react';
import {
  CrownIcon,
  GaugeIcon,
  GiftIcon,
  HourglassIcon,
  MailIcon,
  SlidersHorizontalIcon,
  UserMinusIcon,
  UserPlusIcon,
} from 'lucide-react';

import type { ActivityEventType } from '@/lib/schema/activity.schema';
import { Badge } from '@/components/ui/badge';
import type { ActivityRow } from '../customer-drawer.activity-rows';
import { STAFF_ONLY_LABEL } from '../customer-drawer.activity.copy';
import {
  EMPTY_ACTIVITY_BODY,
  EMPTY_ACTIVITY_TITLE,
  EMPTY_FILTERED_BODY,
  EMPTY_FILTERED_TITLE,
} from '../customer-drawer.copy';
import { DrawerTimeline } from './DrawerTimeline';

const EVENT_ICONS: Record<ActivityEventType, ComponentType<{ className?: string }>> = {
  membership_started: UserPlusIcon,
  points_adjusted: SlidersHorizontalIcon,
  tier_changed: CrownIcon,
  expiry_reset: HourglassIcon,
  reward_redeemed: GiftIcon,
  balance_email_sent: MailIcon,
  expiring_soon_email_sent: MailIcon,
  earning_limited: GaugeIcon,
  account_deleted: UserMinusIcon,
};

/** Activity as a timeline: time and actor under each event, with a STAFF ONLY badge on rows that quote an admin note. */
export function ActivityLogTabRows({
  rows,
  isLoading,
  isFiltered,
}: {
  rows: ActivityRow[];
  isLoading: boolean;
  isFiltered: boolean;
}) {
  return (
    <DrawerTimeline
      isLoading={isLoading}
      emptyTitle={isFiltered ? EMPTY_FILTERED_TITLE() : EMPTY_ACTIVITY_TITLE()}
      emptyBody={isFiltered ? EMPTY_FILTERED_BODY() : EMPTY_ACTIVITY_BODY()}
      items={rows.map((row, index) => ({
        key: index,
        date: row.date,
        icon: EVENT_ICONS[row.eventType],
        title: row.title,
        subLines: [row.subLine, `${row.time} · ${row.actorLabel}`],
        trailing: row.isStaffOnly && (
          <Badge variant="outline" className="text-[10px] tracking-wide">
            {STAFF_ONLY_LABEL()}
          </Badge>
        ),
      }))}
    />
  );
}
