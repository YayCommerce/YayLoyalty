import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { ReferralLayout } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for `referral`: the referral program is Pro only, so the route 404s in Lite. */
export default function ReferralPage() {
  return IS_PRO_BUILD ? <ReferralLayout /> : <NotFoundPage />;
}
