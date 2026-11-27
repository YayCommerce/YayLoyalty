import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { Avatar, AvatarBadge, AvatarFallback } from '@/components/ui/avatar';

/** Ring and badge colours for the first three places: gold, black, grey. */
const PODIUM = [
  { ring: 'ring-warning', badge: 'bg-warning text-white' },
  { ring: 'ring-foreground', badge: 'bg-foreground text-background' },
  { ring: 'ring-muted-foreground', badge: 'bg-muted-foreground text-background' },
];

/** "Jason Ninja" → "JN", "customer" → "CU". */
function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  const letters = words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? '').slice(0, 2);

  return letters.toUpperCase() || '?';
}

/**
 * A tinted circle with a name's initials, or with `label` in its place (a rank number). A `rank` of 1–3 adds the
 * podium ring and a badge holding `badgeIcon`; a later rank gets a light grey ring.
 */
export function DashboardInitialsAvatar({
  name,
  label,
  rank,
  badgeIcon,
  className,
}: {
  name: string;
  label?: ReactNode;
  rank?: number;
  badgeIcon?: ReactNode;
  className?: string;
}) {
  const podium = rank ? PODIUM[rank - 1] : undefined;

  return (
    <Avatar
      className={cn(
        'size-8',
        rank && ['ring-offset-card ring-[1.5px] ring-offset-2', podium?.ring ?? 'ring-border'],
        className,
      )}
    >
      <AvatarFallback className="bg-chart-1/10 text-chart-1 text-xs font-semibold">
        {label ?? initials(name)}
      </AvatarFallback>
      {podium && badgeIcon ? (
        <AvatarBadge
          className={cn(
            '-right-1 -bottom-1 group-data-[size=default]/avatar:size-4 group-data-[size=default]/avatar:[&>svg]:size-2.5',
            podium.badge,
          )}
        >
          {badgeIcon}
        </AvatarBadge>
      ) : null}
    </Avatar>
  );
}
