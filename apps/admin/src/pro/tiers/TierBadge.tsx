/** A tier's colour swatch and name, the same on every screen that names a tier. */
export function TierBadge({ name, color }: { name: string; color: string }) {
  return (
    <span className="flex items-center gap-2">
      <span className="size-3 shrink-0 rounded-full border" style={{ backgroundColor: color }} aria-hidden />
      {name}
    </span>
  );
}
