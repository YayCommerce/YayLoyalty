import { __ } from '@wordpress/i18n';

import { Card, CardContent } from '@/components/ui/card';

export default function GeneralSettingsPage() {
  return (
    <Card>
      <CardContent className="text-muted-foreground text-sm">
        {__('General settings are not implemented yet.', 'yayloyalty')}
      </CardContent>
    </Card>
  );
}
