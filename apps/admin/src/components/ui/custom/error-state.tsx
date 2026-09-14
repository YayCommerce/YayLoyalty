import { AlertTriangle, Ban, ServerCrash, WifiOff } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/**
 * What kind of failure this is — drives which icon shows today, and is the
 * hook for a per-kind layout/copy later (a merchant needs a different next
 * step for "you're offline" than for "you can't access this").
 */
export type ErrorStateKind = 'generic' | 'network' | 'not-found' | 'forbidden' | 'server';

const KIND_ICON: Record<ErrorStateKind, typeof AlertTriangle> = {
  generic: AlertTriangle,
  network: WifiOff,
  'not-found': Ban,
  forbidden: Ban,
  server: ServerCrash,
};

/**
 * One shared place for "a query/mutation failed" UI across the admin app —
 * every page used to hand-roll its own `<p className="text-destructive ...">`
 * (see CustomersPage.tsx / CampaignsPage.tsx before this). Same minimal
 * inline banner as those did, but with the extension points already wired
 * in instead of every call site needing a second pass later:
 *
 * - `kind` picks the icon now (`KIND_ICON` above) and is where a genuinely
 *   different layout/copy per failure type (offline vs 403 vs 500) plugs in.
 * - `onRetry` slots a retry action in without changing the call sites that
 *   don't pass one.
 *
 * Callers only have `kind="generic"` to reach for today — nothing yet
 * distinguishes a network failure from a 500 (see `customersQuery.error` /
 * `campaigns.error`, currently unread) — but the prop exists so that mapping
 * is a change in one place, not a hunt through every page.
 */
export function ErrorState({
  message,
  kind = 'generic',
  onRetry,
  className,
}: {
  message: string;
  kind?: ErrorStateKind;
  onRetry?: () => void;
  className?: string;
}) {
  const Icon = KIND_ICON[kind];

  return (
    <Alert destructive className={className}>
      <Icon />
      <AlertDescription className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span>{message}</span>
        {onRetry && (
          <Button variant="link" size="sm" className="text-destructive h-auto p-0" onClick={onRetry}>
            {__('Try again', 'yayloyalty')}
          </Button>
        )}
      </AlertDescription>
    </Alert>
  );
}
