import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import PageLayout from '@/components/layout/PageLayout';
import { TierCatalog } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for `tiers`: tiers are Pro, so Lite 404s the route. IS_PRO_BUILD, not ProGate, so the @/pro import is tree-shaken. */
export default function TiersPage() {
  return IS_PRO_BUILD ? (
    <PageLayout title={__('Tiers', 'yayloyalty')}>
      <TierCatalog />
    </PageLayout>
  ) : (
    <NotFoundPage />
  );
}
