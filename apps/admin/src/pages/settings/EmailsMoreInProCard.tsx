import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ProGate } from '@/components/pro/ProGate';

/** Lite's upsell below the emails table; static, so the loading skeleton shows it too. */
export function EmailsMoreInProCard() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('More emails in Pro', 'yayloyalty')}</CardTitle>
        <CardDescription>{__('More loyalty emails come with YayLoyalty Pro.', 'yayloyalty')}</CardDescription>
      </CardHeader>
      <CardContent>
        <ProGate />
      </CardContent>
    </Card>
  );
}
