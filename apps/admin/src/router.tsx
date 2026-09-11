import { createHashRouter, redirect } from 'react-router';

import AppLayout from './AppLayout';
import NotFoundPage from './pages/404';
import ErrorPage from './pages/500';
import CampaignsPage from './pages/campaigns/CampaignsPage';
import CustomersPage from './pages/customers/CustomersPage';
import DashboardPage from './pages/dashboard/DashboardPage';
import ReferralPage from './pages/referral/ReferralPage';
import RewardsPage from './pages/rewards/RewardsPage';
import AdvancedSettingsPage from './pages/settings/AdvancedSettingsPage';
import EmailsSettingsPage from './pages/settings/EmailsSettingsPage';
import GeneralSettingsPage from './pages/settings/GeneralSettingsPage';
import SettingsLayout from './pages/settings/SettingsLayout';
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
          path: 'customers',
          element: <CustomersPage />,
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
          element: <SettingsLayout />,
          children: [
            {
              index: true,
              loader: () => redirect('/settings/general'),
            },
            {
              path: 'general',
              element: <GeneralSettingsPage />,
            },
            {
              path: 'emails',
              element: <EmailsSettingsPage />,
            },
            {
              path: 'advanced',
              element: <AdvancedSettingsPage />,
            },
            {
              path: '*',
              loader: () => redirect('/settings/general'),
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
