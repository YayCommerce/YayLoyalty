import { TierIconChip } from '../tiers/TierIconChip';
import { useTiersQuery } from '../tiers/tiers.queries';

/** A Customers row's tier. A deleted tier or no tier reads "—". */
export function CustomersTierCell({ tierId }: { tierId: number | null }) {
  const tier = useTiersQuery().data?.find((item) => item.id === tierId);

  if (!tier) {
    return <span className="text-muted-foreground">—</span>;
  }

  return (
    <span className="flex items-center gap-2.5">
      <TierIconChip color={tier.color} />
      {tier.name}
    </span>
  );
}
