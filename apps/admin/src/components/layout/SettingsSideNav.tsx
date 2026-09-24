import { NavLink } from 'react-router';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { SideNavMenuItem, SideNavMenuList } from '@/components/ui/navmenu-side';

/** Single source of truth for the settings tabs — add a tab here and in `router.tsx`. */
export const SETTINGS_NAV = [
  { to: '/settings/general', label: __('General', 'yayloyalty') },
  { to: '/settings/emails', label: __('Emails', 'yayloyalty') },
  { to: '/settings/advanced', label: __('Advanced', 'yayloyalty') },
];

export default function SettingsSideNav({ className }: { className?: string }) {
  return (
    <SideNavMenuList className={cn('w-full flex-wrap', className)}>
      {SETTINGS_NAV.map((item) => (
        <SideNavMenuItem asChild key={item.to}>
          <NavLink to={item.to}>{item.label}</NavLink>
        </SideNavMenuItem>
      ))}
    </SideNavMenuList>
  );
}
