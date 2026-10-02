import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import PageLayout from '@/components/layout/PageLayout';
import { RewardTable } from '@/pro';
import BuiltInRewardEditor from './BuiltInRewardEditor';

/** Lite and Pro each get their own rewards surface rather than one component branching internally, same split as CampaignsPage. */
export default function RewardsPage() {
  return (
    <PageLayout
      title={__('Rewards', 'yayloyalty')}
      description={IS_PRO_BUILD ? undefined : __('How customers spend their points.', 'yayloyalty')}
    >
      {/* IS_PRO_BUILD, not ProGate, so the @/pro import is tree-shaken. */}
      {IS_PRO_BUILD && <RewardTable />}

      {!IS_PRO_BUILD && <BuiltInRewardEditor />}
    </PageLayout>
  );
}
