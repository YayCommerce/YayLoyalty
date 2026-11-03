import { useParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CampaignCreator } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for campaigns/new/:trigger. */
export default function CampaignCreatePage() {
  const { trigger } = useParams();

  return IS_PRO_BUILD ? <CampaignCreator trigger={trigger ?? ''} /> : <NotFoundPage />;
}
