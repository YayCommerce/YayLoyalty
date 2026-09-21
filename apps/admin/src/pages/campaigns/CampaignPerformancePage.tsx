import { useParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CampaignPerformance } from '@/pro';
import NotFoundPage from '../404';

/**
 * Shared, Lite-safe entry for `campaigns/:id/performance` — mirrors
 * `CampaignEditorPage.tsx`. Lite has no performance page for any campaign, so
 * this route 404s there rather than showing an upsell for a URL Lite has no
 * concept of.
 *
 * Imports `CampaignPerformance` from the `@/pro` barrel, never
 * `@/pro/campaigns/performance/...` directly — see that barrel's docblock for
 * why a deep import here would drag the whole Pro subtree into the Lite bundle.
 */
export default function CampaignPerformancePage() {
  const { id } = useParams();

  return IS_PRO_BUILD ? <CampaignPerformance id={Number.parseInt(id ?? '', 10)} /> : <NotFoundPage />;
}
