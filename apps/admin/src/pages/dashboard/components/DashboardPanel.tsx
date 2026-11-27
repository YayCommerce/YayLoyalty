import type { ReactNode } from 'react';
import { Link } from 'react-router';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

/** A main column beside a side column; each row's cards stretch to the taller one. */
export const DASHBOARD_ROW = 'grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]';

/** A dashboard card: a bordered header row (title, optional note and action) above the body. */
export function DashboardPanel({
  title,
  note,
  action,
  children,
}: {
  title: ReactNode;
  note?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <Card className="gap-0 p-0">
      <div className="flex min-h-16 flex-wrap items-center justify-between gap-3 border-b px-6 py-3">
        <div className="flex min-w-0 flex-col">
          <h2 className="m-0 flex items-center gap-2 text-base font-semibold">{title}</h2>
          {note ? <span className="text-muted-foreground text-xs">{note}</span> : null}
        </div>
        {action}
      </div>
      {children}
    </Card>
  );
}

/** The outline button in a panel header that links to a full page. */
export function DashboardPanelLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Button variant="outline" size="sm" asChild>
      <Link to={to}>{children}</Link>
    </Button>
  );
}
