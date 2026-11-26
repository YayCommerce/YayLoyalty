import TierCrownIcon from '@/components/icons/TierCrownIcon';

/** A tier's crown on a tint of its colour, beside its name on the Tiers and Customers tables. */
export function TierIconChip({ color }: { color: string }) {
  return (
    <span
      className="flex size-7 shrink-0 items-center justify-center rounded-md"
      style={{ backgroundColor: `color-mix(in srgb, ${color} 15%, transparent)`, color }}
      aria-hidden
    >
      <TierCrownIcon className="size-4" />
    </span>
  );
}
