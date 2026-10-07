import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { RewardTemplatePicker } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for rewards/new. Lite has no catalog to add to, so the route 404s there. */
export default function RewardAddPage() {
  return IS_PRO_BUILD ? <RewardTemplatePicker /> : <NotFoundPage />;
}
