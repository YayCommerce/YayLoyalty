import { __ } from '@wordpress/i18n';

import { Card, CardContent } from '@/components/ui/card';

export default function AdvancedSettingsPage() {
  return (
    <Card>
      <CardContent className="text-muted-foreground text-sm">
        {__('Advanced settings are not implemented yet.', 'yayloyalty')}
      </CardContent>
    </Card>
  );
}
