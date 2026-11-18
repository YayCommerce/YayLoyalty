import { NavLink, Outlet } from 'react-router';
import { __ } from '@wordpress/i18n';

import { SideNavMenuItem, SideNavMenuList } from '@/components/ui/navmenu-side';
import PageLayout from '@/components/layout/PageLayout';

/** The Referral tabs. */
const REFERRAL_TABS = [{ to: '/referral/settings', label: __('Settings', 'yayloyalty') }];

/** Referral shell: the header and tab links stay mounted while `<Outlet/>` swaps the active tab. */
export function ReferralLayout() {
  return (
    <PageLayout title={__('Referral', 'yayloyalty')}>
      <div className="flex flex-col gap-4">
        <SideNavMenuList className="w-fit justify-start sm:flex-row">
          {REFERRAL_TABS.map((tab) => (
            <SideNavMenuItem asChild key={tab.to} className="w-auto px-3">
              <NavLink to={tab.to}>{tab.label}</NavLink>
            </SideNavMenuItem>
          ))}
        </SideNavMenuList>
        <Outlet />
      </div>
    </PageLayout>
  );
}
