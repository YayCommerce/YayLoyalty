import { ComponentProps } from 'react';
import { Coins, Crown, Gift, Share2 } from 'lucide-react';
import { NavLink } from 'react-router';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { HeaderNavMenuItem, HeaderNavMenuList } from '@/components/ui/navmenu-header';
import DashboardIcon from '@/components/icons/DashboardIcon';
import SettingsIcon from '@/components/icons/SettingsIcon';

export function HeaderMenu() {
  return (
    <HeaderNavMenuList className="*:data-[slot=header-navigation-menu-indicator]:bg-primary">
      <CustomMenuItem asChild key="dashboard">
        <NavLink to="/dashboard">
          <DashboardIcon className="stroke-2" />
          <span>{__('Dashboard', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="points">
        <NavLink to="/points">
          <Coins className="stroke-2" />
          <span>{__('Points', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="rewards">
        <NavLink to="/rewards">
          <Gift className="stroke-2" />
          <span>{__('Rewards', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="referral">
        <NavLink to="/referral">
          <Share2 className="stroke-2" />
          <span>{__('Referral', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="vip-tiers">
        <NavLink to="/vip-tiers">
          <Crown className="stroke-2" />
          <span>{__('VIP Tiers', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
      <CustomMenuItem asChild key="settings">
        <NavLink to="/settings">
          <SettingsIcon className="stroke-2" />
          <span>{__('Settings', 'yayloyalty')}</span>
        </NavLink>
      </CustomMenuItem>
    </HeaderNavMenuList>
  );
}

function CustomMenuItem({ className, ...props }: ComponentProps<typeof HeaderNavMenuItem>) {
  return (
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
  );
}
