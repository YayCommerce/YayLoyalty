import { formatPercent, formatWPCurrency } from '@/lib/helpers/format.helper';
import { pointsPhrase } from '@/lib/helpers/points.helper';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { REFERRAL_SETTINGS_COPY as COPY, offerSentence } from './referral-settings.copy';
import type { ReferralSettingsFormValues } from './referral-settings.form';

/** "$10" or "10%": what the friend gets, as the customer reads it. */
function discountText(values: ReferralSettingsFormValues): string {
  if (values.discount_type === 'percent') {
    return formatPercent(values.discount_amount, 2);
  }

  return formatWPCurrency(values.discount_amount);
}

/** A read-only render of the customer's offer card from the current form values. */
export function ReferralOfferPreview({ values }: { values: ReferralSettingsFormValues }) {
  const discount = discountText(values);
  const points = pointsPhrase(values.advocate_points);
  const minimum = values.min_order > 0 ? formatWPCurrency(values.min_order) : null;
  const link = `${window.yayLoyaltyMeta.wpMeta.homeUrl.replace(/\/$/, '')}/?yl_ref=…`;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{COPY.previewTitle()}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="flex flex-col gap-3 rounded-md border p-4">
          <p className="text-base font-semibold">{COPY.offerHeading(discount, points)}</p>
          <p className="text-muted-foreground text-sm">{offerSentence(discount, minimum, points, values.hold_days)}</p>
          <span className="text-sm font-medium">{COPY.yourLink()}</span>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input readOnly tabIndex={-1} dir="ltr" value={link} aria-label={COPY.yourLink()} />
            <Button type="button" variant="outline" disabled>
              {COPY.copyLink()}
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
