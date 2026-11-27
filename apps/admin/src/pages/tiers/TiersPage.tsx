import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Card } from '@/components/ui/card';
import PageLayout from '@/components/layout/PageLayout';
import { TierCatalog, TierModeCard, TierSwitchCard } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for `tiers`: tiers are Pro, so Lite 404s the route. IS_PRO_BUILD, not ProGate, so the @/pro import is tree-shaken. */
export default function TiersPage() {
  return IS_PRO_BUILD ? (
    <PageLayout
      title={__('Tiers', 'yayloyalty')}
      description={__('Reward your most loyal customers with higher tiers.', 'yayloyalty')}
    >
      <div className="grid items-start gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <Card className="gap-0 p-0">
          <TierSwitchCard />
          <TierModeCard />
        </Card>
        <TierCatalog />
      </div>
    </PageLayout>
  ) : (
    <NotFoundPage />
  );
}
