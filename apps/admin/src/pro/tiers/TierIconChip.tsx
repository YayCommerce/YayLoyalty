import { cn } from '@/lib/utils';
import TierCrownIcon from '@/components/icons/TierCrownIcon';

/** A tier's crown on a tint of its colour, beside its name on the Tiers and Customers tables and in tier pickers. */
export function TierIconChip({ color, size = 'default' }: { color: string; size?: 'default' | 'sm' }) {
  return (
    <span
      className={cn('flex shrink-0 items-center justify-center rounded-md', size === 'sm' ? 'size-5' : 'size-7')}
      style={{ backgroundColor: `color-mix(in srgb, ${color} 15%, transparent)`, color }}
      aria-hidden
    >
      {/* `text-current`: inherit the tier colour even where a parent styles bare SVGs (a select item does). */}
      <TierCrownIcon className={cn('text-current', size === 'sm' ? 'size-3' : 'size-4')} />
    </span>
  );
}
