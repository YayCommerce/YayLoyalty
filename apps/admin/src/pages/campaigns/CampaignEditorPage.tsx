import { useParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CampaignEditor } from '@/pro';
import NotFoundPage from '../404';

/**
 * Lite-safe entry for campaigns/:id/edit. Lite has no campaign editor, so
 * the route 404s there rather than upselling a URL Lite has no concept of.
 * Imports from the `@/pro` barrel, never a deep path, so Lite stays
 * tree-shaken.
 */
export default function CampaignEditorPage() {
  const { id } = useParams();

  return IS_PRO_BUILD ? <CampaignEditor id={Number.parseInt(id ?? '', 10)} /> : <NotFoundPage />;
}
