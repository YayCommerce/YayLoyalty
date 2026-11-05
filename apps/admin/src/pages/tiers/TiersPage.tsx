import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import PageLayout from '@/components/layout/PageLayout';
import { TierCatalog, TierModeCard, TierSwitchCard } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for `tiers`: tiers are Pro, so Lite 404s the route. IS_PRO_BUILD, not ProGate, so the @/pro import is tree-shaken. */
export default function TiersPage() {
  return IS_PRO_BUILD ? (
    <PageLayout title={__('Tiers', 'yayloyalty')}>
      <div className="flex flex-col gap-4">
        <TierSwitchCard />
        <TierModeCard />
        <TierCatalog />
      </div>
    </PageLayout>
  ) : (
    <NotFoundPage />
  );
}
