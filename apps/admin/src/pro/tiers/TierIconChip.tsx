import { cn } from '@/lib/utils';
import TierCrownIcon from '@/components/icons/TierCrownIcon';

const BOX = { sm: 'size-5', default: 'size-7', lg: 'size-10' } as const;
const ICON = { sm: 'size-3', default: 'size-4', lg: 'size-5' } as const;

/**
 * A tier's crown on a tint of its colour, beside its name on the Tiers and Customers tables, in tier pickers and on
 * a customer's tier card; muted when there is no tier (null).
 */
export function TierIconChip({ color, size = 'default' }: { color: string | null; size?: keyof typeof BOX }) {
  return (
    <span
      className={cn(
        'flex shrink-0 items-center justify-center rounded-md',
        BOX[size],
        color === null && 'bg-muted text-muted-foreground',
      )}
      style={color === null ? undefined : { backgroundColor: `color-mix(in srgb, ${color} 15%, transparent)`, color }}
      aria-hidden
    >
      {/* `text-current`: inherit the tier colour even where a parent styles bare SVGs (a select item does). */}
      <TierCrownIcon className={cn('text-current', ICON[size])} />
    </span>
  );
}
