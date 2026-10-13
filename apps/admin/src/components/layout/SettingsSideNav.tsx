import { NavLink } from 'react-router';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { SideNavMenuItem, SideNavMenuList } from '@/components/ui/navmenu-side';

/** The settings tabs. A new tab also needs its route in `router.tsx`. */
export const SETTINGS_NAV = [
  { to: '/settings/general', label: __('General', 'yayloyalty') },
  { to: '/settings/expiry', label: __('Expiry', 'yayloyalty') },
  { to: '/settings/design', label: __('Design', 'yayloyalty') },
  { to: '/settings/emails', label: __('Emails', 'yayloyalty') },
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
