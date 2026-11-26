import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';

/** "Jason Ninja" → "JN", "customer" → "CU". */
function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  const letters = words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? '').slice(0, 2);

  return letters.toUpperCase() || '?';
}

/** A tinted circle with a name's initials, or with `label` in its place (a rank number). */
export function DashboardInitialsAvatar({
  name,
  label,
  className,
}: {
  name: string;
  label?: ReactNode;
  className?: string;
}) {
  return (
    <Avatar className={cn('size-8', className)}>
      <AvatarFallback className="bg-chart-1/10 text-chart-1 text-xs font-semibold">
        {label ?? initials(name)}
      </AvatarFallback>
    </Avatar>
  );
}
