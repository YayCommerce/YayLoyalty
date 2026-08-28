import { __ } from '@wordpress/i18n';

import PagePlaceholder from '@/pages/PagePlaceholder';

export default function DashboardPage() {
  return <PagePlaceholder title={__('Dashboard', 'yayloyalty')} description={__('Overview of points, rewards, referrals and VIP activity.', 'yayloyalty')} />;
}
