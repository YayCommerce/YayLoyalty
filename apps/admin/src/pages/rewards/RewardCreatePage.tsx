import { useParams, useSearchParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { RewardCreator } from '@/pro';
import NotFoundPage from '../404';

/**
 * Lite-safe entry for rewards/new/:type. Lite has no catalog, so the route
 * 404s there. `?from=<id>` is RewardTable's Duplicate action; a missing or
 * non-positive value is treated as absent, same as CampaignCreatePage.
 */
export default function RewardCreatePage() {
  const { type } = useParams();
  const [searchParams] = useSearchParams();
  const fromParam = searchParams.get('from');
  const duplicateFromId = fromParam ? Number.parseInt(fromParam, 10) : undefined;

  return IS_PRO_BUILD ? (
    <RewardCreator
      type={type ?? ''}
      duplicateFromId={duplicateFromId && duplicateFromId > 0 ? duplicateFromId : undefined}
    />
  ) : (
    <NotFoundPage />
  );
}
