import { createHashRouter, redirect } from 'react-router';

import AppLayout from './AppLayout';
import NotFoundPage from './pages/404';
import ErrorPage from './pages/500';
import CampaignsPage from './pages/campaigns/CampaignsPage';
import DashboardPage from './pages/dashboard/DashboardPage';
import PointsPage from './pages/points/PointsPage';
import ReferralPage from './pages/referral/ReferralPage';
import RewardsPage from './pages/rewards/RewardsPage';
import SettingsPage from './pages/settings/SettingsPage';
import VipTiersPage from './pages/vip-tiers/VipTiersPage';

export function getManagerRouter() {
  return createHashRouter([
    {
      path: '/',
      element: <AppLayout />,
      errorElement: <ErrorPage />,
      children: [
        {
          index: true,
          loader: () => redirect('/dashboard'),
        },
        {
          path: 'dashboard',
          element: <DashboardPage />,
        },
        {
          path: 'points',
          element: <PointsPage />,
        },
        {
          path: 'campaigns',
          element: <CampaignsPage />,
        },
        {
          path: 'rewards',
          element: <RewardsPage />,
        },
        {
          path: 'referral',
          element: <ReferralPage />,
        },
        {
          path: 'vip-tiers',
          element: <VipTiersPage />,
        },
        {
          path: 'settings',
          children: [
            {
              index: true,
              loader: () => redirect('/settings/general'),
            },
            {
              path: ':subMenu',
              loader: ({ params }) => {
                if (!params.subMenu || !settingSubMenus.includes(params.subMenu)) {
                  return redirect('/settings/general');
                }
              },
              element: <SettingsPage />,
            },
          ],
        },
        {
          path: '*',
          element: <NotFoundPage />,
        },
      ],
    },
  ]);
}

const settingSubMenus = ['general', 'emails', 'advanced'];
