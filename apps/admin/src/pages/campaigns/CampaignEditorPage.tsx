import { useParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CampaignEditor } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for campaigns/:id/edit: Lite has no campaign editor, so the route 404s there. */
export default function CampaignEditorPage() {
  const { id } = useParams();

  return IS_PRO_BUILD ? <CampaignEditor id={Number.parseInt(id ?? '', 10)} /> : <NotFoundPage />;
}
