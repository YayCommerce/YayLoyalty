import { Link } from 'react-router';

import { Button } from '@/components/ui/button';
import { SETUP_PENDING_ACTION, SETUP_PENDING_TITLE } from '../dashboard-page.copy';

/** While setup is pending: the way back into the wizard. Not dismissible; it goes once the program is turned on. */
export function DashboardSetupPending() {
  return (
    <div
      role="status"
      className="bg-card border-l-muted-foreground/40 flex flex-col gap-2 rounded-md border border-l-4 p-4"
    >
      <div className="text-sm font-semibold">{SETUP_PENDING_TITLE()}</div>
      <div>
        <Button asChild variant="primary" size="sm">
          <Link to="/setup">{SETUP_PENDING_ACTION()}</Link>
        </Button>
      </div>
    </div>
  );
}
