import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useParams } from '@/lib/router';
import { CampaignPerformance } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for campaigns/:id/performance: Lite has no performance page, so the route 404s there. */
export default function CampaignPerformancePage() {
  const { id } = useParams();

  return IS_PRO_BUILD ? <CampaignPerformance id={Number.parseInt(id ?? '', 10)} /> : <NotFoundPage />;
}
