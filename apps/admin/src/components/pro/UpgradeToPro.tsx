import { ChevronRight, InfoIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';

const yayLoyaltyProUrl = 'https://yaycommerce.com/yayloyalty-for-woocommerce/';

export function UpgradeToProBadge() {
  return (
    <YayLoyaltyToolTip
      trigger={
        <Badge
          variant="warning"
          className="text-3 h-4.5 cursor-pointer rounded-sm p-1.5 pr-0.75 leading-6 text-white"
          onClick={(event) => {
            // The badge can sit inside another clickable element, such as `<ProGate inline />`
            // in a `TabsTrigger`; without this, a click on it also switches the tab.
            event.stopPropagation();
            window.open(yayLoyaltyProUrl);
          }}
        >
          {__('Pro', 'yayloyalty')}
          <ChevronRight className="h-0.75 w-1.25" />
        </Badge>
      }
      content={__('Unlock this feature')}
    />
  );
}

/** The yellow Upgrade button every upsell surface shares. */
function UpgradeButton({ size }: { size?: 'sm' }) {
  return (
    <Button variant="warning" size={size} className="shrink-0 text-white" onClick={() => window.open(yayLoyaltyProUrl)}>
      {__('Upgrade', 'yayloyalty')}
    </Button>
  );
}

export function UpgradeToProOverlay() {
  return (
    <Empty className="my-5">
      <EmptyHeader>
        <EmptyMedia variant="icon" className="size-15 bg-[#FFF5DB]">
          <img
            src={`${window.yayLoyaltyMeta.loyaltyMeta.assetsUrl}/images/logo/logo-yay.png`}
            alt="YayLoyalty"
            className="size-13"
          />
        </EmptyMedia>
        <EmptyTitle className="flex items-center gap-1.5 font-bold">
          {__('YayLoyalty', 'yayloyalty')}
          <Badge variant="warning" className="text-white">
            {__('Pro', 'yayloyalty')}
          </Badge>
        </EmptyTitle>
        <EmptyDescription>{__('Upgrade to PRO to unlock this feature now', 'yayloyalty')}</EmptyDescription>
        <EmptyContent>
          <UpgradeButton />
        </EmptyContent>
      </EmptyHeader>
    </Empty>
  );
}

/** A Lite screen's closing strip: what Pro adds there, in that screen's words, plus the Upgrade button. */
export function UpgradeToProBanner({ message }: { message: string }) {
  return (
    <Alert variant="warning" className="items-center [&>svg]:translate-y-0">
      <InfoIcon />
      <AlertDescription className="text-foreground flex flex-row items-center justify-between gap-3">
        <span>{message}</span>
        <UpgradeButton size="sm" />
      </AlertDescription>
    </Alert>
  );
}
