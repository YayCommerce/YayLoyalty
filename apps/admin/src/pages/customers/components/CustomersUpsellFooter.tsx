import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

/**
 * Lite-only footer promo. Sits at the foot of a working screen and scrolls
 * with it — it must never cover the table, which rules out `<ProGate>`
 * (non-inline mode renders `UpgradeToProOverlay`, a centered dashed panel
 * meant to replace content, not sit below it). Gate this at the call site
 * with `{!__IS_PRO__ && …}` instead — see `CustomersPage.tsx`.
 */
export function CustomersUpsellFooter() {
  return (
    <Card className="bg-muted/30 flex-row items-center gap-4 py-4">
      <CardContent className="flex flex-1 flex-col gap-1">
        <span className="flex items-center gap-1.5 text-sm font-semibold">
          {__('Pro adds tiers, referrals and bulk point adjustments', 'yayloyalty')}
          <Badge variant="warning">{__('Pro', 'yayloyalty')}</Badge>
        </span>
        <span className="text-muted-foreground text-sm">
          {__('Filter and segment members by tier, and act on many customers at once.', 'yayloyalty')}
        </span>
      </CardContent>
      <Button variant="link" size="sm">
        {__('Compare Pro', 'yayloyalty')}
      </Button>
    </Card>
  );
}
