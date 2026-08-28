import { __ } from '@wordpress/i18n';

import PagePlaceholder from '@/pages/PagePlaceholder';

export default function RewardsPage() {
  return <PagePlaceholder title={__('Rewards', 'yayloyalty')} description={__('Manage redeemable rewards and coupons.', 'yayloyalty')} />;
}
