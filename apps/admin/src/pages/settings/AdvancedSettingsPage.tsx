import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ProGate } from '@/components/pro/ProGate';
import { AdvancedFraudLimitsForm } from '@/pro';
import { FRAUD_LIMITS_TITLE, FRAUD_LIMITS_UPSELL } from './advanced-settings.copy';

/** Pro edits the fraud limits; Lite shows the same card as an upsell, with no input and no Save. */
export default function AdvancedSettingsPage() {
  if (IS_PRO_BUILD) {
    return <AdvancedFraudLimitsForm />;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{FRAUD_LIMITS_TITLE()}</CardTitle>
        <CardDescription>{FRAUD_LIMITS_UPSELL()}</CardDescription>
      </CardHeader>
      <CardContent>
        <ProGate />
      </CardContent>
    </Card>
  );
}
