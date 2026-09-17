import { Link } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * "Your points program is live" (artboard 2a) — shown while the store has no
 * members and no points issued. Not a wizard: a fresh install already runs
 * three campaigns and one reward, so this confirms what is live and links
 * to where it is edited.
 *
 * STATIC COPY ON PURPOSE. Reading the campaigns would mean `GET /campaigns`,
 * which needs MANAGE_CAMPAIGNS — a VIEW-only user would get a 403 and a
 * toast on the landing page. The built-ins are fixed by the installer, so
 * naming them here is safe; the edit links are capability-gated instead.
 */
export function DashboardEmptyState({ pointsPerCurrencyUnit }: { pointsPerCurrencyUnit: number }) {
  const one = parseWPCurrency(1);
  const builtIns = [
    sprintf(
      /* translators: %s: one unit of store currency. */ __('Points for purchases — 1 pt per %s', 'yayloyalty'),
      one,
    ),
    pointsPerCurrencyUnit > 0
      ? sprintf(
          /* translators: 1: points, 2: one unit of store currency. */ __(
            'Points for discount — %1$s points = %2$s',
            'yayloyalty',
          ),
          formatPoints(pointsPerCurrencyUnit),
          one,
        )
      : __('Points for discount', 'yayloyalty'),
    __('Account signup — 100 pts', 'yayloyalty'),
    __('Product review — 50 pts', 'yayloyalty'),
  ];

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('Your points program is live', 'yayloyalty')}</CardTitle>
        <CardDescription>
          {__(
            'Three campaigns are already running and one reward is available. Figures on this page fill in as customers earn and redeem.',
            'yayloyalty',
          )}
        </CardDescription>
      </CardHeader>
      <ul className="text-muted-foreground grid gap-1 text-sm sm:grid-cols-2">
        {builtIns.map((line) => (
          <li key={line}>{line}</li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-4 text-sm">
        <Can cap={CAP.MANAGE_CAMPAIGNS}>
          <Link to="/campaigns" className="text-primary hover:underline">
            {__('Edit campaigns', 'yayloyalty')}
          </Link>
        </Can>
        <Can cap={CAP.MANAGE_REWARDS}>
          <Link to="/rewards" className="text-primary hover:underline">
            {__('Edit rewards', 'yayloyalty')}
          </Link>
        </Can>
        <Link to="/settings/general" className="text-primary hover:underline">
          {__('Set points expiry', 'yayloyalty')}
        </Link>
      </div>
    </Card>
  );
}
