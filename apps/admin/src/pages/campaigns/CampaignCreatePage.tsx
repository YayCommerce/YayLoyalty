import { useParams, useSearchParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { CampaignCreator } from '@/pro';
import NotFoundPage from '../404';

/**
 * Lite-safe entry for campaigns/new/:templateId. `?from=<id>` is
 * CampaignTable's Duplicate action; a missing or non-positive value is
 * treated as absent rather than passed through, and CampaignCreator's own
 * fetch 404s if that id turns out not to exist.
 */
export default function CampaignCreatePage() {
  const { templateId } = useParams();
  const [searchParams] = useSearchParams();
  const fromParam = searchParams.get('from');
  const duplicateFromId = fromParam ? Number.parseInt(fromParam, 10) : undefined;

  return IS_PRO_BUILD ? (
    <CampaignCreator
      templateId={templateId ?? ''}
      duplicateFromId={duplicateFromId && duplicateFromId > 0 ? duplicateFromId : undefined}
    />
  ) : (
    <NotFoundPage />
  );
}
