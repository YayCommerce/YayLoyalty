import { __ } from '@wordpress/i18n';

import PagePlaceholder from '@/pages/PagePlaceholder';

export default function VipTiersPage() {
  return (
    <PagePlaceholder
      title={__('Tiers', 'yayloyalty')}
      description={__('Define VIP tiers, milestones and perks.', 'yayloyalty')}
    />
  );
}
