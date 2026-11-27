import { NavLink, Outlet } from 'react-router';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import PageLayout from '@/components/layout/PageLayout';
import { APPROVALS_COPY } from './approvals/approvals.copy';
import { ReferralApprovalsBadge } from './approvals/ReferralApprovalsBadge';
import { REFERRALS_COPY } from './referrals.copy';

/** The Referral tabs. Labels are functions, so `__()` runs after the locale loads. */
const REFERRAL_TABS = [
  { to: '/referral/referrals', label: REFERRALS_COPY.tab },
  { to: '/referral/approvals', label: APPROVALS_COPY.tab, badge: true },
  { to: '/referral/settings', label: () => __('Settings', 'yayloyalty') },
];

/** Referral shell: the header and tab links stay mounted while `<Outlet/>` swaps the active tab. */
export function ReferralLayout() {
  return (
    <PageLayout title={__('Referral', 'yayloyalty')}>
      <div className="flex flex-col gap-4">
        <div className="max-w-full overflow-x-auto">
          <nav className="bg-muted text-muted-foreground inline-flex h-9 w-max items-center gap-1 rounded-full p-1">
            {REFERRAL_TABS.map((tab) => (
              <NavLink
                key={tab.to}
                to={tab.to}
                className={({ isActive }) =>
                  cn(
                    'hover:text-foreground focus-visible:ring-ring/50 inline-flex h-full items-center gap-1.5 rounded-full px-3 text-sm font-medium whitespace-nowrap outline-none transition focus:shadow-none focus-visible:ring-[3px]',
                    isActive && 'bg-background text-foreground shadow-sm focus:shadow-sm',
                  )
                }
              >
                {tab.label()}
                {tab.badge && <ReferralApprovalsBadge />}
              </NavLink>
            ))}
          </nav>
        </div>
        <Outlet />
      </div>
    </PageLayout>
  );
}
