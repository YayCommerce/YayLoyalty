import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import PageLayout from '@/components/layout/PageLayout';
import { CampaignTable } from '@/pro';
import BuiltInCampaignList from './BuiltInCampaignList';

/**
 * Lite and Pro each get their own campaigns surface — see CampaignTable's and
 * BuiltInCampaignList's own docblocks for why (grouped/priority table vs.
 * three always-present editable cards) rather than one component branching
 * internally on `IS_PRO_BUILD`.
 */
export default function CampaignsPage() {
  return (
    <PageLayout
      title={__('Campaigns', 'yayloyalty')}
      description={__('Earning rules. Built-in campaigns cannot be deleted.', 'yayloyalty')}
    >
      {/*
        Pro gets its own richer table component (grouped, drag-to-reorder,
        priority) rather than an enhanced version of the Lite markup below —
        guarded by IS_PRO_BUILD (not ProGate) so the @/pro import is
        tree-shaken — see the @/pro barrel comment.
      */}
      {IS_PRO_BUILD && <CampaignTable />}

      {!IS_PRO_BUILD && <BuiltInCampaignList />}
    </PageLayout>
  );
}
