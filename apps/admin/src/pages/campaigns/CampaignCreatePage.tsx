import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useParams, useSearchParams } from '@/lib/router';
import { CampaignCreator } from '@/pro';
import NotFoundPage from '../404';

/**
 * Lite-safe entry for campaigns/new/:templateId. `?from=<id>` is CampaignTable's Duplicate action;
 * a missing or non-positive id counts as absent, and CampaignCreator's fetch 404s on an unknown one.
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
