import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CampaignTemplatePicker } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for campaigns/new: Lite has no campaign creation, so the route 404s there. */
export default function CampaignAddPage() {
  return IS_PRO_BUILD ? <CampaignTemplatePicker /> : <NotFoundPage />;
}
