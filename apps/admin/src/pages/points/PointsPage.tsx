import { __ } from '@wordpress/i18n';

import PagePlaceholder from '@/pages/PagePlaceholder';

export default function PointsPage() {
  return <PagePlaceholder title={__('Points', 'yayloyalty')} description={__('Configure earning and spending rules for loyalty points.', 'yayloyalty')} />;
}
