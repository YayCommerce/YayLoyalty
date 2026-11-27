import { useState, type ComponentType, type ReactNode } from 'react';
import { ClockIcon } from 'lucide-react';
import { _n, sprintf } from '@wordpress/i18n';

import { formatCount } from '@/lib/helpers/points.helper';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import { DRAWER_PAGE_SIZE } from './DrawerTabPager';

/** One entry on a drawer tab's timeline. */
export type DrawerTimelineItem = {
  key: string | number;
  /** Formatted date; consecutive items with the same date share a group. */
  date: string;
  icon: ComponentType<{ className?: string }>;
  title: ReactNode;
  /** Muted lines under the title; empty ones are skipped. */
  subLines?: ReactNode[];
  /** Badges or marks on the right. */
  trailing?: ReactNode;
  /** Tinted with a left rule, like the drawer's panels. */
  isMarked?: boolean;
};

/** Consecutive items that share a date; every tab's page arrives newest first, so one pass groups it. */
function groupByDate(items: DrawerTimelineItem[]): { date: string; items: DrawerTimelineItem[] }[] {
  const groups: { date: string; items: DrawerTimelineItem[] }[] = [];

  for (const item of items) {
    const last = groups[groups.length - 1];

    if (last && last.date === item.date) {
      last.items.push(item);
    } else {
      groups.push({ date: item.date, items: [item] });
    }
  }

  return groups;
}

/** The shell every drawer tab shares: skeleton while loading, an empty state, or one collapsible group per day. */
export function DrawerTimeline({
  items,
  isLoading,
  emptyTitle,
  emptyBody,
}: {
  items: DrawerTimelineItem[];
  isLoading: boolean;
  emptyTitle: string;
  emptyBody: string;
}) {
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2">
        {Array.from({ length: DRAWER_PAGE_SIZE }, (_, index) => (
          <Skeleton key={index} className="h-10 w-full" />
        ))}
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <div className="rounded-md border px-4 py-12 text-center">
        <p className="text-sm font-medium">{emptyTitle}</p>
        <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">{emptyBody}</p>
      </div>
    );
  }

  function toggle(date: string) {
    setCollapsed((current) => {
      const next = new Set(current);

      if (next.has(date)) {
        next.delete(date);
      } else {
        next.add(date);
      }

      return next;
    });
  }

  return (
    <div className="flex flex-col gap-3">
      {groupByDate(items).map((group) => {
        const isOpen = !collapsed.has(group.date);

        return (
          <div key={group.date} className="flex flex-col">
            <button
              type="button"
              aria-expanded={isOpen}
              onClick={() => toggle(group.date)}
              className="bg-muted/50 hover:bg-muted flex items-center gap-3 rounded-md border px-3 py-2 text-left text-xs"
            >
              <ClockIcon className="text-muted-foreground size-4" />
              <span className="text-muted-foreground font-semibold">{group.date}</span>
              {!isOpen && (
                <span className="text-muted-foreground border-l pl-3">
                  {sprintf(
                    /* translators: %s: the number of entries on that day. */
                    _n('%s activity', '%s activities', group.items.length, 'yayloyalty'),
                    formatCount(group.items.length),
                  )}
                </span>
              )}
            </button>
            {isOpen && (
              <ul className="ml-5 flex flex-col border-l pl-4">
                {group.items.map((item) => (
                  <DrawerTimelineEntry key={item.key} item={item} />
                ))}
              </ul>
            )}
          </div>
        );
      })}
    </div>
  );
}

function DrawerTimelineEntry({ item }: { item: DrawerTimelineItem }) {
  const Icon = item.icon;

  return (
    <li
      className={cn(
        'flex items-start gap-3 rounded-md px-2 py-2.5',
        item.isMarked && 'bg-muted/50 shadow-[inset_3px_0_0_0_var(--muted-foreground)]',
      )}
    >
      <span className="bg-muted text-muted-foreground flex size-6 shrink-0 items-center justify-center rounded-sm">
        <Icon className="size-3.5" />
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-0.5 text-sm">
        <span>{item.title}</span>
        {item.subLines
          ?.filter((line) => line !== null && line !== undefined && line !== '' && line !== false)
          .map((line, index) => (
            <span key={index} className="text-muted-foreground text-xs">
              {line}
            </span>
          ))}
      </span>
      {item.trailing && <span className="flex shrink-0 flex-col items-end gap-1">{item.trailing}</span>}
    </li>
  );
}
