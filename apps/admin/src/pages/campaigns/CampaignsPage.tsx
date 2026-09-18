import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import PageLayout from '@/components/layout/PageLayout';
import { CampaignTable } from '@/pro';
import BuiltInCampaignList from './BuiltInCampaignList';

/** Lite and Pro each get their own campaigns surface rather than one component branching internally. */
export default function CampaignsPage() {
  return (
    <PageLayout
      title={__('Campaigns', 'yayloyalty')}
      description={__('Earning rules. Built-in campaigns cannot be deleted.', 'yayloyalty')}
    >
      {/* IS_PRO_BUILD, not ProGate, so the @/pro import is tree-shaken. */}
      {IS_PRO_BUILD && <CampaignTable />}

      {!IS_PRO_BUILD && <BuiltInCampaignList />}
    </PageLayout>
  );
}
