import { ReactElement } from 'react';
import { createHashRouter, redirect } from 'react-router';

import { Can, CAP, Capability } from '@/lib/access';
import AppLayout from './AppLayout';
import NotFoundPage from './pages/404';
import ErrorPage from './pages/500';
import CampaignAddPage from './pages/campaigns/CampaignAddPage';
import CampaignCreatePage from './pages/campaigns/CampaignCreatePage';
import CampaignEditorPage from './pages/campaigns/CampaignEditorPage';
import CampaignPerformancePage from './pages/campaigns/CampaignPerformancePage';
import CampaignsPage from './pages/campaigns/CampaignsPage';
import CustomersPage from './pages/customers/CustomersPage';
import CustomerDrawer from './pages/customers/drawer/CustomerDrawer';
import DashboardPage from './pages/dashboard/DashboardPage';
import ReferralPage from './pages/referral/ReferralPage';
import RewardsPage from './pages/rewards/RewardsPage';
import AdvancedSettingsPage from './pages/settings/AdvancedSettingsPage';
import DesignSettingsPage from './pages/settings/DesignSettingsPage';
import EmailsSettingsPage from './pages/settings/EmailsSettingsPage';
import GeneralSettingsPage from './pages/settings/GeneralSettingsPage';
import SettingsLayout from './pages/settings/SettingsLayout';
import VipTiersPage from './pages/vip-tiers/VipTiersPage';

/**
 * A route the user cannot reach 404s inside the SPA rather than rendering an
 * empty screen. Same cap as the matching HeaderMenu item, so nav and URL agree.
 */
function guarded(cap: Capability, element: ReactElement) {
  return (
    <Can cap={cap} fallback={<NotFoundPage />}>
      {element}
    </Can>
  );
}

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
          element: guarded(CAP.VIEW, <DashboardPage />),
        },
        {
          path: 'customers',
          element: guarded(CAP.VIEW, <CustomersPage />),
          // The parent's `guarded()` already checks CAP.VIEW for this child.
          children: [{ path: ':userId', element: <CustomerDrawer /> }],
        },
        {
          path: 'campaigns',
          element: guarded(CAP.MANAGE_CAMPAIGNS, <CampaignsPage />),
        },
        {
          path: 'campaigns/new',
          element: guarded(CAP.MANAGE_CAMPAIGNS, <CampaignAddPage />),
        },
        {
          path: 'campaigns/new/:templateId',
          element: guarded(CAP.MANAGE_CAMPAIGNS, <CampaignCreatePage />),
        },
        {
          path: 'campaigns/:id/edit',
          element: guarded(CAP.MANAGE_CAMPAIGNS, <CampaignEditorPage />),
        },
        {
          path: 'campaigns/:id/performance',
          element: guarded(CAP.MANAGE_CAMPAIGNS, <CampaignPerformancePage />),
        },
        {
          path: 'rewards',
          element: guarded(CAP.MANAGE_REWARDS, <RewardsPage />),
        },
        {
          path: 'referral',
          element: guarded(CAP.MANAGE_CAMPAIGNS, <ReferralPage />),
        },
        {
          path: 'vip-tiers',
          element: guarded(CAP.MANAGE_TIERS, <VipTiersPage />),
        },
        {
          path: 'settings',
          element: guarded(CAP.VIEW, <SettingsLayout />),
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
              path: 'design',
              element: <DesignSettingsPage />,
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
