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
      <div className="flex flex-col gap-4 sm:flex-row">
        <SettingsSideNav className="sm:w-48 sm:shrink-0" />
        <div className="flex-1">
          <Outlet />
        </div>
      </div>
    </PageLayout>
  );
}
