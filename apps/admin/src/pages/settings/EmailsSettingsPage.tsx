import { __ } from '@wordpress/i18n';

import { Card, CardContent } from '@/components/ui/card';

export default function EmailsSettingsPage() {
  return (
    <Card>
      <CardContent className="text-muted-foreground text-sm">
        {__('Email settings are not implemented yet.', 'yayloyalty')}
      </CardContent>
    </Card>
  );
}
