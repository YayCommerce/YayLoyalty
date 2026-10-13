import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ProGate } from '@/components/pro/ProGate';

/** Lite's upsell below the inactivity card. */
export function ExpiryMoreInProCard() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Points expire in batches', 'yayloyalty')}</CardTitle>
        <CardDescription>
          {__('Expire each earning a set time after it was earned. Available in YayLoyalty Pro.', 'yayloyalty')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ProGate />
      </CardContent>
    </Card>
  );
}
