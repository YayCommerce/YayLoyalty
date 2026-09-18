import { Link } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { formatPoints } from '@/lib/helpers/points.helper';
import { pctOf } from '@/lib/points-math/points-math';
import type { DashboardTierDistribution as Distribution } from '@/lib/schema/dashboard-pro.schema';
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ProGate } from '@/components/pro/ProGate';

const COLORS = ['var(--chart-1)', 'var(--chart-3)', 'var(--chart-4)'];

/** One stacked bar plus a legend row per tier — "Explorer 1,847 · 76.0%". */
export function DashboardTierDistribution({ tiers }: { tiers: Readonly<NonNullable<Distribution>> }) {
  const total = tiers.reduce((sum, t) => sum + t.members, 0);
  // pctOf is null on an empty total; the legend keeps reading 0.0% rather than growing a "—" state for a dummy card.
  const pct = (members: number) => pctOf(members, total) ?? 0;

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {__('Tier distribution', 'yayloyalty')}
          <ProGate inline />
        </CardTitle>
        <CardDescription>
          {sprintf(_n('%s member', '%s members', total, 'yayloyalty'), formatPoints(total))}
        </CardDescription>
        <CardAction>
          <Can cap={CAP.MANAGE_TIERS}>
            <Link to="/vip-tiers" className="text-primary text-sm hover:underline">
              {__('Manage tiers', 'yayloyalty')}
            </Link>
          </Can>
        </CardAction>
      </CardHeader>
      <div
        className="bg-muted flex h-2 w-full overflow-hidden rounded-full"
        role="img"
        aria-label={__('Members per tier', 'yayloyalty')}
      >
        {tiers.map((t, i) => (
          <div key={t.name} style={{ width: `${pct(t.members)}%`, backgroundColor: COLORS[i % COLORS.length] }} />
        ))}
      </div>
      <ul className="flex flex-col gap-1 text-sm">
        {tiers.map((t, i) => (
          <li key={t.name} className="flex items-center gap-2">
            <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: COLORS[i % COLORS.length] }} />
            <span>{t.name}</span>
            <span className="text-muted-foreground tabular-nums">
              {sprintf(__('%1$s · %2$s%%', 'yayloyalty'), formatPoints(t.members), pct(t.members).toFixed(1))}
            </span>
          </li>
        ))}
      </ul>
    </Card>
  );
}
