import { __ } from '@wordpress/i18n';

import PagePlaceholder from '@/pages/PagePlaceholder';

export default function ReferralPage() {
  return <PagePlaceholder title={__('Referral', 'yayloyalty')} description={__('Configure the referral program for advocates and friends.', 'yayloyalty')} />;
}
