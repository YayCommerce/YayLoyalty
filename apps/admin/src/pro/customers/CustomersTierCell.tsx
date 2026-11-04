import { TierBadge } from '../tiers/TierBadge';
import { useTiersQuery } from '../tiers/tiers.queries';

/** A Customers row's tier. A deleted tier or no tier reads "—". */
export function CustomersTierCell({ tierId }: { tierId: number | null }) {
  const tier = useTiersQuery().data?.find((item) => item.id === tierId);

  if (tierId === null || !tier) {
    return <span className="text-muted-foreground">—</span>;
  }

  return <TierBadge name={tier.name} color={tier.color} />;
}
