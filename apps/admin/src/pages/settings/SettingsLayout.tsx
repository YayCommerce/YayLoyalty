import { Outlet } from 'react-router';
import { __ } from '@wordpress/i18n';

import PageLayout from '@/components/layout/PageLayout';
import SettingsSideNav from '@/components/layout/SettingsSideNav';

/** Settings shell: header + side nav stay mounted while `<Outlet/>` swaps the active tab. */
export default function SettingsLayout() {
  return (
    <PageLayout
      title={__('Settings', 'yayloyalty')}
      description={__('Configure how the loyalty program behaves.', 'yayloyalty')}
    >
      {/* items-start: without it, the row stretches the side nav's <ul> to match
          the content column's height, and navmenu-side.tsx's justify-center then
          centers the nav items inside that tall box instead of pinning them to the top. */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
        <SettingsSideNav className="sm:w-48 sm:shrink-0" />
        <div className="flex-1">
          <Outlet />
        </div>
      </div>
    </PageLayout>
  );
}
