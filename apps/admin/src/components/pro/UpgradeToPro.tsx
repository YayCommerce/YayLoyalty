import { ChevronRight } from 'lucide-react';
import { __ } from '@wordpress/i18n';

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

export function UpgradeToProOverlay() {
  return (
    <Empty className="my-5">
      <EmptyHeader>
        <EmptyMedia variant="icon" className="size-15 bg-[#FFF5DB]">
          <img
            src={`${window.yayLoyaltyMeta.loyaltyMeta.assetsUrl}/images/logo/favicon.svg`}
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
          <Button
            variant="warning"
            className="text-white"
            onClick={() => {
              window.open(yayLoyaltyProUrl);
            }}
          >
            {__('Upgrade', 'yayloyalty')}
          </Button>
        </EmptyContent>
      </EmptyHeader>
    </Empty>
  );
}
