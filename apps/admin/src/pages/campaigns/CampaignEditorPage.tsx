import { useParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CampaignEditor } from '@/pro';
import NotFoundPage from '../404';

/**
 * Shared, Lite-safe entry for `campaigns/:id/edit` — mirrors CampaignsPage's
 * `IS_PRO_BUILD` branch. Lite has no create/edit UI for anything but
 * BuiltInCampaignList's three always-present cards, so this route 404s there
 * rather than showing an upsell for a URL Lite has no concept of.
 *
 * Imports `CampaignEditor` from the `@/pro` barrel, never
 * `@/pro/campaigns/editor/...` directly — see that barrel's docblock for why
 * a deep import here would drag the whole Pro subtree into the Lite bundle.
 */
export default function CampaignEditorPage() {
  const { id } = useParams();

  return IS_PRO_BUILD ? <CampaignEditor id={Number.parseInt(id ?? '', 10)} /> : <NotFoundPage />;
}
