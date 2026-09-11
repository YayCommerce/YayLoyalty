import { ComponentProps } from 'react';
import { Coins, Crown, Gift, Megaphone, Share2 } from 'lucide-react';
import { NavLink } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Can, CAP, Capability } from '@/lib/access';
import { cn } from '@/lib/utils';
import { HeaderNavMenuItem, HeaderNavMenuList } from '@/components/ui/navmenu-header';
import DashboardIcon from '@/components/icons/DashboardIcon';
import SettingsIcon from '@/components/icons/SettingsIcon';

export function HeaderMenu() {
  return (
    <HeaderNavMenuList className="*:data-[slot=header-navigation-menu-indicator]:bg-primary">
      <CustomMenuItem asChild key="dashboard" cap={CAP.VIEW}>
        <NavLink to="/dashboard">
          <DashboardIcon className="stroke-2" />
          <span>{__('Dashboard', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="customers" cap={CAP.VIEW}>
        <NavLink to="/customers">
          <Coins className="stroke-2" />
          <span>{__('Customers', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="campaigns" cap={CAP.MANAGE_CAMPAIGNS}>
        <NavLink to="/campaigns">
          <Megaphone className="stroke-2" />
          <span>{__('Campaigns', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="rewards" cap={CAP.MANAGE_REWARDS}>
        <NavLink to="/rewards">
          <Gift className="stroke-2" />
          <span>{__('Rewards', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="referral" cap={CAP.MANAGE_CAMPAIGNS}>
        <NavLink to="/referral">
          <Share2 className="stroke-2" />
          <span>{__('Referral', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="vip-tiers" cap={CAP.MANAGE_TIERS}>
        <NavLink to="/vip-tiers">
          <Crown className="stroke-2" />
          <span>{__('Tiers', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="settings" cap={CAP.VIEW}>
        <NavLink to="/settings">
          <SettingsIcon className="stroke-2" />
          <span>{__('Settings', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
    </HeaderNavMenuList>
  );
}

/**
 * A screen the user cannot view is simply not in the nav — a smaller nav is
 * not confusing, an empty screen is. router.tsx applies the same cap to the
 * route so a pasted hash lands on the 404 page.
 */
function CustomMenuItem({ cap, className, ...props }: ComponentProps<typeof HeaderNavMenuItem> & { cap: Capability }) {
  return (
    <Can cap={cap}>
      <HeaderNavMenuItem
        className={cn(
          'max-md:flex-col sm:max-md:px-3',
          'sm:max-md:pt-2',
          '*:[svg]:size-4 sm:*:[svg]:size-3.5 md:*:[svg]:size-4',
          'max-md:text-xs max-sm:*:[span]:hidden',
          'aria-[current=page]:text-primary',
          className,
        )}
        {...props}
      />
    </Can>
  );
}
