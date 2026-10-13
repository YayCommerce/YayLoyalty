import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ProGate } from '@/components/pro/ProGate';
import { EXPIRY_PRO_DESCRIPTION, EXPIRY_PRO_FEATURES, EXPIRY_PRO_TITLE } from './expiry-settings.copy';

/** Lite's place for Pro's expiry sections: what they add, with the upgrade prompt and no inputs. */
export function ExpiryProUpsellCard() {
  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{EXPIRY_PRO_TITLE()}</CardTitle>
        <CardDescription>{EXPIRY_PRO_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <ul className="text-muted-foreground flex list-disc flex-col gap-1 pl-5 text-sm">
          {EXPIRY_PRO_FEATURES().map((feature) => (
            <li key={feature}>{feature}</li>
          ))}
        </ul>
        <ProGate />
      </CardContent>
    </Card>
  );
}
