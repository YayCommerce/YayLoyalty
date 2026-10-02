import { useParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { RewardCreator } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for rewards/new/:type. Lite has no catalog, so the route 404s there. */
export default function RewardCreatePage() {
  const { type } = useParams();

  return IS_PRO_BUILD ? <RewardCreator type={type ?? ''} /> : <NotFoundPage />;
}
