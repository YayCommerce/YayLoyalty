import { useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router';

import { can, CAP } from '@/lib/access';
import { Toaster } from '@/components/ui/sonner';
import Footer from '@/components/layout/Footer';
import Header from '@/components/layout/Header';
import { SetupWizardModal } from '@/components/setup-wizard/SetupWizardModal';

export default function AppLayout() {
  const navigate = useNavigate();
  const location = useLocation();
  const pathSegments = location.pathname.split('/').filter(Boolean);
  // The wizard writes settings, so only a user who may manage them sees it. Finishing saves and exiting
  // saves nothing; either way it never opens again.
  const [showSetup, setShowSetup] = useState(
    () => !window.yayLoyaltyMeta.loyaltyMeta.setupCompleted && can(CAP.MANAGE_SETTINGS),
  );
  return (
    <div>
      <Header />

      <main>
        <Toaster />
        {showSetup && (
          <SetupWizardModal
            onClose={() => {
              window.yayLoyaltyMeta.loyaltyMeta.setupCompleted = true;
              setShowSetup(false);
            }}
          />
        )}
        <Outlet />
      </main>

      <Footer
        pathSegments={pathSegments.length ? pathSegments : ['dashboard']}
        onBackToDefault={() => navigate('/dashboard')}
      />
    </div>
  );
}
