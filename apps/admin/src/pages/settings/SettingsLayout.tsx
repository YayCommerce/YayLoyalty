import { Outlet } from 'react-router';
import { __ } from '@wordpress/i18n';

import PageLayout from '@/components/layout/PageLayout';
import SettingsSideNav from '@/components/layout/SettingsSideNav';

/** Settings shell: header + side nav stay mounted while `<Outlet/>` swaps the active tab. */
export default function SettingsLayout() {
  return (
    // Dashboard width: the Design tab's live preview sits beside its form.
    <PageLayout
      title={__('Settings', 'yayloyalty')}
      description={__('Configure how the loyalty program behaves.', 'yayloyalty')}
    >
      {/* items-start: otherwise the row stretches the side nav to the content's height, and
          navmenu-side.tsx's justify-center centres the items instead of pinning them to the top. */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
        <SettingsSideNav className="sm:w-48 sm:shrink-0" />
        <div className="min-w-0 flex-1">
          <Outlet />
        </div>
      </div>
    </PageLayout>
  );
}
