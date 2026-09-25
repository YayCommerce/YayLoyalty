import { cn } from '@/lib/utils';

/**
 * Loading placeholder. `tone="page"` is for a skeleton that sits directly on
 * the admin page background, which bg-muted all but matches (both are a
 * near-white grey), so the default tone disappears there.
 */
function Skeleton({ className, tone = 'card', ...props }: React.ComponentProps<'div'> & { tone?: 'card' | 'page' }) {
  return (
    <div
      data-slot="skeleton"
      className={cn('animate-pulse rounded-md', tone === 'page' ? 'bg-muted-foreground/15' : 'bg-muted', className)}
      {...props}
    />
  );
}

export { Skeleton };
